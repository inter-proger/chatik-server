<?php

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/WebSocket.php';
require_once __DIR__ . '/../core/ErrorCode.php';
require_once __DIR__ . '/WsFrame.php';
require_once __DIR__ . '/WsMessage.php';

/** Соединение клиента WebSocket */
class WsConnection
{
    const STATE_HANDSHAKE  = 'handshake';
    const STATE_PENDING    = 'pending';     // handshake пройден, ждём auth / connect
    const STATE_AUTHORIZED = 'authorized';

    public string $state = self::STATE_HANDSHAKE;
    public string $in  = '';
    public string $out = '';

    public ?int $userId = null;
    public ?string $sessionId = null;

    /** Начало фрагментированного сообщения (null — фрагментов нет) */
    public ?string $fragments = null;

    /** Отправлен close-фрейм: после этого ждём ответного close или срока $closeDeadline */
    public bool $closing = false;
    public float $closeDeadline = 0;

    /** Закрыть TCP, как только $out будет отправлен */
    public bool $dropAfterFlush = false;

    /** Неподтверждённые события: uid => время отправки, в порядке отправки */
    public array $unacked = [];

    public float $lastFrameAt;

    public function __construct(public $socket, public float $openedAt)
    {
        $this->lastFrameAt = $openedAt;
    }
}

/** Внутреннее HTTP-соединение от API (publish, closeSession) */
class WsInternalConnection
{
    public string $in  = '';
    public string $out = '';
    public bool $responded = false;

    public function __construct(public $socket, public float $openedAt)
    {
    }
}

/**
 * WebSocket-сервер: один процесс, цикл на stream_select().
 * Принимает клиентов на $options['listen'] и вызовы API на 127.0.0.1:$options['internalPort'].
 * Протокол — docs/api/WebSocket.md; устройство — docs/architecture/WebSocket.md.
 */
class WsServer
{
    private const HANDSHAKE_MAX_SIZE = 8192;
    private const INTERNAL_MAX_SIZE  = 8 * 1024 * 1024;
    private const INTERNAL_TIMEOUT   = 5;
    /** Внутренние запросы короткие; лимит учтён в WEBSOCKET_MAX_CONNECTIONS (config/websocket.php) */
    private const INTERNAL_MAX_CONNECTIONS = 16;
    private const CLOSE_TIMEOUT      = 2;
    private const TICK_INTERVAL      = 0.25;

    private const CLOSE_NORMAL       = 1000;
    private const CLOSE_GOING_AWAY   = 1001;
    private const CLOSE_AUTH_FAILED  = 4001;
    private const CLOSE_AUTH_TIMEOUT = 4002;

    private $listener;
    private $internalListener;

    /** @var array<int, WsConnection> по id сокета */
    private array $clients = [];
    /** @var array<int, WsInternalConnection> */
    private array $internal = [];

    /** user_id => [id сокета => true], только авторизованные незакрывающиеся соединения */
    private array $byUser = [];
    /** session_id => [id сокета => true] */
    private array $bySession = [];

    private bool $running = true;
    private float $lastTick = 0;

    /**
     * $options: listen, internalPort, secret, authTimeout, idleTimeout, maxMessageSize, ackTimeout, maxUnacked,
     * maxConnections, maxOutputBuffer
     */
    public function __construct(private array $options)
    {
    }

    public function run(): void
    {
        $this->listener         = $this->listen($this->options['listen']);
        $this->internalListener = $this->listen('tcp://127.0.0.1:' . $this->options['internalPort']);
        $this->log("Listening on {$this->options['listen']}, internal port {$this->options['internalPort']}");

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function () {
                $this->running = false;
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        while ($this->running) {
            $read  = [$this->listener, $this->internalListener];
            $write = [];
            foreach ([...$this->clients, ...$this->internal] as $conn) {
                $read[] = $conn->socket;
                if ($conn->out !== '') {
                    $write[] = $conn->socket;
                }
            }
            $except = null;

            // false — прерван сигналом
            if (@stream_select($read, $write, $except, 0, (int) (self::TICK_INTERVAL * 1_000_000)) !== false) {
                foreach ($read as $socket) {
                    $this->onReadable($socket);
                }
                foreach ($write as $socket) {
                    $this->onWritable($socket);
                }
            }

            $now = microtime(true);
            if ($now - $this->lastTick >= self::TICK_INTERVAL) {
                $this->lastTick = $now;
                $this->tick($now);
            }
        }

        $this->shutdown();
    }

    // ---- Сокеты ----

    private function listen(string $address)
    {
        $socket = stream_socket_server($address, $errno, $error);
        if ($socket === false) {
            throw new RuntimeException("Cannot listen on $address: $error");
        }
        stream_set_blocking($socket, false);

        return $socket;
    }

    private function onReadable($socket): void
    {
        if ($socket === $this->listener || $socket === $this->internalListener) {
            $conn = @stream_socket_accept($socket, 0);
            if ($conn === false) {
                return;
            }
            // Сверх лимита соединение сразу закрываем: дескриптор >= FD_SETSIZE сломал бы stream_select()
            $isClient = $socket === $this->listener;
            if ($isClient ? count($this->clients) >= $this->options['maxConnections']
                          : count($this->internal) >= self::INTERNAL_MAX_CONNECTIONS) {
                @fclose($conn);
                $this->log(($isClient ? 'Client' : 'Internal') . ' connection limit reached, connection refused');
                return;
            }
            stream_set_blocking($conn, false);
            $now = microtime(true);
            if ($isClient) {
                $this->clients[(int) $conn] = new WsConnection($conn, $now);
            } else {
                $this->internal[(int) $conn] = new WsInternalConnection($conn, $now);
            }
            return;
        }

        $id = (int) $socket;
        // Соединение могло быть закрыто раньше в этой же итерации
        if (!isset($this->clients[$id]) && !isset($this->internal[$id])) {
            return;
        }

        $data = @fread($socket, 65536);
        if ($data === false || ($data === '' && feof($socket))) {
            $this->drop($id);
            return;
        }

        if (isset($this->clients[$id])) {
            $this->clients[$id]->in .= $data;
            $this->processClient($this->clients[$id]);
        } else {
            $this->internal[$id]->in .= $data;
            $this->processInternal($this->internal[$id]);
        }
    }

    private function onWritable($socket): void
    {
        $id   = (int) $socket;
        $conn = $this->clients[$id] ?? $this->internal[$id] ?? null;
        if ($conn === null) {
            return;
        }

        $written = @fwrite($socket, $conn->out);
        if ($written === false) {
            $this->drop($id);
            return;
        }
        $conn->out = (string) substr($conn->out, $written);

        if ($conn->out === '' && ($conn instanceof WsInternalConnection ? $conn->responded : $conn->dropAfterFlush)) {
            $this->drop($id);
        }
    }

    /** Закрывает TCP-соединение без close-фрейма */
    private function drop(int $id): void
    {
        $conn = $this->clients[$id] ?? $this->internal[$id] ?? null;
        if ($conn === null) {
            return;
        }
        $this->unindex($id);
        unset($this->clients[$id], $this->internal[$id]);
        @fclose($conn->socket);
    }

    // ---- Клиенты ----

    private function processClient(WsConnection $conn): void
    {
        if ($conn->state === WsConnection::STATE_HANDSHAKE && !$this->handshake($conn)) {
            return;
        }

        try {
            while (!$conn->dropAfterFlush && isset($this->clients[(int) $conn->socket])
                && ($frame = WsFrame::decode($conn->in, true, $this->options['maxMessageSize'])) !== null) {
                $conn->lastFrameAt = microtime(true);
                $this->onFrame($conn, $frame);
            }
        } catch (WsProtocolError $e) {
            $this->close($conn, $e->getCode());
            $conn->in = '';
        }
    }

    /** Разбирает HTTP-запрос на апгрейд. false — запрос ещё не пришёл целиком или отклонён. */
    private function handshake(WsConnection $conn): bool
    {
        $end = strpos($conn->in, "\r\n\r\n");
        if ($end === false) {
            if (strlen($conn->in) > self::HANDSHAKE_MAX_SIZE) {
                $this->rejectHandshake($conn);
            }
            return false;
        }

        $lines   = explode("\r\n", substr($conn->in, 0, $end));
        $request = array_shift($lines);
        $headers = self::parseHeaders($lines);

        $key = $headers['sec-websocket-key'] ?? '';
        if (!str_starts_with($request, 'GET ')
            || stripos($headers['upgrade'] ?? '', 'websocket') === false
            || ($headers['sec-websocket-version'] ?? '') !== '13'
            || strlen((string) base64_decode($key, true)) !== 16) {
            $this->rejectHandshake($conn);
            return false;
        }

        $conn->out .= "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . WsFrame::acceptKey($key) . "\r\n\r\n";
        $conn->in    = (string) substr($conn->in, $end + 4);
        $conn->state = WsConnection::STATE_PENDING;

        return true;
    }

    private function rejectHandshake(WsConnection $conn): void
    {
        $conn->out .= "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
        $conn->in = '';
        $conn->dropAfterFlush = true;
    }

    private function onFrame(WsConnection $conn, array $frame): void
    {
        switch ($frame['opcode']) {
            case WsFrame::OP_CLOSE:
                // Ответ на наш close или close клиента (отвечаем тем же кодом) — после отправки закрываем TCP
                $this->close($conn, WsFrame::closeCode($frame['payload']) ?? self::CLOSE_NORMAL);
                $conn->dropAfterFlush = true;
                if ($conn->out === '') {
                    $this->drop((int) $conn->socket);
                }
                return;

            case WsFrame::OP_PING:
                $this->write($conn, WsFrame::encode(WsFrame::OP_PONG, $frame['payload']));
                return;

            case WsFrame::OP_PONG:
                return;

            case WsFrame::OP_TEXT:
                if ($conn->fragments !== null) {
                    throw new WsProtocolError('New message inside fragmented one', WsFrame::CLOSE_PROTOCOL_ERROR);
                }
                if ($frame['fin']) {
                    $this->onMessage($conn, $frame['payload']);
                } else {
                    $conn->fragments = $frame['payload'];
                }
                return;

            case WsFrame::OP_CONTINUATION:
                if ($conn->fragments === null) {
                    throw new WsProtocolError('Unexpected continuation frame', WsFrame::CLOSE_PROTOCOL_ERROR);
                }
                $conn->fragments .= $frame['payload'];
                if (strlen($conn->fragments) > $this->options['maxMessageSize']) {
                    throw new WsProtocolError('Message too big', WsFrame::CLOSE_TOO_BIG);
                }
                if ($frame['fin']) {
                    $message = $conn->fragments;
                    $conn->fragments = null;
                    $this->onMessage($conn, $message);
                }
                return;

            default:
                // Бинарные фреймы протоколом не используются
                throw new WsProtocolError('Binary frames are not supported', WsFrame::CLOSE_PROTOCOL_ERROR);
        }
    }

    private function onMessage(WsConnection $conn, string $json): void
    {
        if ($conn->closing) {
            return;
        }

        $message = WsMessage::parse($json);
        if ($message === null) {
            throw new WsProtocolError('Invalid message', WsFrame::CLOSE_PROTOCOL_ERROR);
        }
        $type = "{$message['group']}/{$message['event']}";

        if ($conn->state === WsConnection::STATE_PENDING) {
            if ($type !== 'auth/connect') {
                throw new WsProtocolError('First message must be auth/connect', WsFrame::CLOSE_PROTOCOL_ERROR);
            }
            $this->authorize($conn, $message['body']);
            return;
        }

        switch ($type) {
            case 'auth/connect':
                throw new WsProtocolError('Already authorized', WsFrame::CLOSE_PROTOCOL_ERROR);

            case 'events/received':
                $uid = $message['body']['receivedUid'] ?? null;
                if (is_string($uid)) {
                    unset($conn->unacked[$uid]);
                }
                return;

            default:
                // Неизвестные события авторизованного клиента игнорируются
                return;
        }
    }

    private function authorize(WsConnection $conn, array $body): void
    {
        $userId = $body['userId'] ?? null;
        $token  = $body['token'] ?? null;
        if (!is_int($userId) || $userId <= 0 || !is_string($token) || $token === '') {
            $this->rejectAuth($conn, ErrorCode::INVALID_PARAMS);
            return;
        }

        try {
            $sessionId = $this->withDb(fn() => WebSocket::consumeToken($token, $userId));
        } catch (Throwable $e) {
            $this->log('Token check failed: ' . $e->getMessage());
            $this->rejectAuth($conn, ErrorCode::INTERNAL_ERROR);
            return;
        }
        if ($sessionId === null) {
            $this->rejectAuth($conn, ErrorCode::WS_TOKEN_INVALID);
            return;
        }

        $id = (int) $conn->socket;
        $conn->state     = WsConnection::STATE_AUTHORIZED;
        $conn->userId    = $userId;
        $conn->sessionId = $sessionId;
        $this->byUser[$userId][$id]       = true;
        $this->bySession[$sessionId][$id] = true;

        $this->send($conn, 'auth', 'connected', []);
    }

    private function rejectAuth(WsConnection $conn, int $errorCode): void
    {
        $this->send($conn, 'auth', 'connectionError', ['errorCode' => $errorCode]);
        $this->close($conn, self::CLOSE_AUTH_FAILED);
    }

    /** Отправляет событие; события всех групп, кроме auth, ждут подтверждения events / received. */
    private function send(WsConnection $conn, string $group, string $event, array|stdClass $body, array $userIds = []): void
    {
        $uid = WsMessage::uuid();
        if (!$this->write($conn, WsFrame::encode(WsFrame::OP_TEXT, WsMessage::encode($uid, $group, $event, $body, $userIds)))) {
            return;
        }

        if ($group !== 'auth') {
            $conn->unacked[$uid] = microtime(true);
            if (count($conn->unacked) > $this->options['maxUnacked']) {
                unset($conn->unacked[array_key_first($conn->unacked)]);
            }
        }
    }

    /** Отправляет close-фрейм; соединение больше не получает событий и закрывается после ответа клиента. */
    private function close(WsConnection $conn, int $code): void
    {
        if ($conn->closing) {
            return;
        }
        $conn->closing       = true;
        $conn->closeDeadline = microtime(true) + self::CLOSE_TIMEOUT;
        $this->unindex((int) $conn->socket);
        $this->write($conn, WsFrame::encode(WsFrame::OP_CLOSE, WsFrame::closePayload($code)));
    }

    /**
     * Добавляет фрейм в исходящий буфер. Если буфер превысил maxOutputBuffer (клиент не читает
     * сокет), соединение разрывается без close-фрейма — false.
     */
    private function write(WsConnection $conn, string $frame): bool
    {
        $conn->out .= $frame;
        if (strlen($conn->out) <= $this->options['maxOutputBuffer']) {
            return true;
        }

        $this->log('Output buffer of ' . ($conn->userId !== null ? "user {$conn->userId}" : 'connection') . ' overflowed, dropping');
        $this->drop((int) $conn->socket);

        return false;
    }

    private function unindex(int $id): void
    {
        $conn = $this->clients[$id] ?? null;
        if ($conn === null || $conn->userId === null) {
            return;
        }
        unset($this->byUser[$conn->userId][$id], $this->bySession[$conn->sessionId][$id]);
        if (empty($this->byUser[$conn->userId])) {
            unset($this->byUser[$conn->userId]);
        }
        if (empty($this->bySession[$conn->sessionId])) {
            unset($this->bySession[$conn->sessionId]);
        }
    }

    /** Таймауты: авторизации, закрытия, тишины от клиента, подтверждений; зависшие внутренние запросы. */
    private function tick(float $now): void
    {
        foreach ($this->clients as $id => $conn) {
            // Ждём ответного close или отправки остатка (ответ 400 на handshake), но не бесконечно
            if ($conn->closing || $conn->dropAfterFlush) {
                $deadline = $conn->closing ? $conn->closeDeadline : $conn->openedAt + self::CLOSE_TIMEOUT;
                if ($now >= $deadline) {
                    $this->drop($id);
                }
                continue;
            }

            if ($conn->state !== WsConnection::STATE_AUTHORIZED) {
                if ($now - $conn->openedAt >= $this->options['authTimeout']) {
                    if ($conn->state === WsConnection::STATE_HANDSHAKE) {
                        $this->drop($id);
                    } else {
                        $this->close($conn, self::CLOSE_AUTH_TIMEOUT);
                    }
                }
                continue;
            }

            if ($now - $conn->lastFrameAt >= $this->options['idleTimeout']) {
                $this->log("Connection of user {$conn->userId} is idle, dropping");
                $this->drop($id);
                continue;
            }

            // unacked упорядочен по времени отправки — проверяем только старые с начала
            foreach ($conn->unacked as $uid => $sentAt) {
                if ($now - $sentAt < $this->options['ackTimeout']) {
                    break;
                }
                $this->log("Event $uid to user {$conn->userId} not acknowledged");
                unset($conn->unacked[$uid]);
            }
        }

        foreach ($this->internal as $id => $conn) {
            if ($now - $conn->openedAt >= self::INTERNAL_TIMEOUT) {
                $this->drop($id);
            }
        }
    }

    // ---- Внутренний HTTP ----

    private function processInternal(WsInternalConnection $conn): void
    {
        if ($conn->responded) {
            return;
        }
        if (strlen($conn->in) > self::INTERNAL_MAX_SIZE) {
            $this->respond($conn, 413, false);
            return;
        }

        $end = strpos($conn->in, "\r\n\r\n");
        if ($end === false) {
            return;
        }

        $lines = explode("\r\n", substr($conn->in, 0, $end));
        [$method, $path] = explode(' ', array_shift($lines)) + ['', ''];
        $headers = self::parseHeaders($lines);

        $length = (int) ($headers['content-length'] ?? 0);
        if (strlen($conn->in) < $end + 4 + $length) {
            return;
        }
        $body = substr($conn->in, $end + 4, $length);

        if (!hash_equals($this->options['secret'], $headers['x-internal-secret'] ?? '')) {
            $this->respond($conn, 403, false);
            return;
        }
        if ($method !== 'POST') {
            $this->respond($conn, 405, false);
            return;
        }

        $data = json_decode($body, false);
        if (!$data instanceof stdClass) {
            $this->respond($conn, 400, false);
            return;
        }

        $ok = match ($path) {
            '/publish'      => $this->publish($data),
            '/closeSession' => $this->closeSession($data),
            default         => null,
        };
        $this->respond($conn, $ok === null ? 404 : ($ok ? 200 : 400), (bool) $ok);
    }

    /** { deliveries: [{ recipientIds: int[], message: { group, event, body, userIds? } }] } */
    private function publish(stdClass $data): bool
    {
        if (!is_array($data->deliveries ?? null)) {
            return false;
        }

        // Сначала проверяем все доставки, чтобы не разослать часть запроса
        foreach ($data->deliveries as $delivery) {
            $message = $delivery->message ?? null;
            if (!is_array($delivery->recipientIds ?? null) || !$message instanceof stdClass
                || !is_string($message->group ?? null) || !is_string($message->event ?? null)
                || !($message->body ?? null) instanceof stdClass) {
                return false;
            }
        }

        foreach ($data->deliveries as $delivery) {
            $message = $delivery->message;
            $userIds = is_array($message->userIds ?? null) ? $message->userIds : [];
            foreach ($delivery->recipientIds as $recipientId) {
                foreach (array_keys($this->byUser[(int) $recipientId] ?? []) as $id) {
                    $this->send($this->clients[$id], $message->group, $message->event, $message->body, $userIds);
                }
            }
        }

        return true;
    }

    /** { sessionId: string, code: int } */
    private function closeSession(stdClass $data): bool
    {
        $code = $data->code ?? null;
        if (!is_string($data->sessionId ?? null) || !is_int($code) || $code < 4000 || $code > 4999) {
            return false;
        }

        foreach (array_keys($this->bySession[$data->sessionId] ?? []) as $id) {
            $this->close($this->clients[$id], $code);
        }

        return true;
    }

    private function respond(WsInternalConnection $conn, int $status, bool $ok): void
    {
        $json = json_encode(['status' => (int) $ok]);
        $text = [200 => 'OK', 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found',
                 405 => 'Method Not Allowed', 413 => 'Payload Too Large'][$status];

        $conn->out .= "HTTP/1.1 $status $text\r\nContent-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($json) . "\r\nConnection: close\r\n\r\n" . $json;
        $conn->in = '';
        $conn->responded = true;
    }

    // ---- Прочее ----

    /** Заголовки HTTP-запроса: имя в нижнем регистре => значение */
    private static function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $headers;
    }

    /** Запрос к БД; если MySQL закрыл соединение (wait_timeout), переподключается и повторяет один раз. */
    private function withDb(callable $query): mixed
    {
        try {
            return $query();
        } catch (PDOException $e) {
            if (!DB::isConnectionLost($e)) {
                throw $e;
            }
            $this->log('Database connection lost, reconnecting');
            DB::reset();

            return $query();
        }
    }

    /** Остановка по сигналу: клиентам — close 1001, чтобы они переподключились. */
    private function shutdown(): void
    {
        $this->log('Shutting down');
        foreach ($this->clients as $id => $conn) {
            if ($conn->state !== WsConnection::STATE_HANDSHAKE) {
                $this->close($conn, self::CLOSE_GOING_AWAY);
                // close() мог разорвать соединение с переполненным буфером
                if (isset($this->clients[$id])) {
                    @fwrite($conn->socket, $conn->out);
                }
            }
            $this->drop($id);
        }
        foreach (array_keys($this->internal) as $id) {
            $this->drop($id);
        }
        fclose($this->listener);
        fclose($this->internalListener);
    }

    private function log(string $message): void
    {
        fwrite(STDERR, date('[Y-m-d H:i:s] ') . $message . "\n");
    }
}
