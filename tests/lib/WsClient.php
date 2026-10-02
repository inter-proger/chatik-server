<?php

require_once __DIR__ . '/../../websocket/WsFrame.php';
require_once __DIR__ . '/../../websocket/WsMessage.php';

/**
 * Минимальный WebSocket-клиент для тестов: handshake, отправка сообщений протокола,
 * приём событий. На ping сервера отвечает pong сам.
 */
class WsClient
{
    private $socket;
    private string $in = '';

    /** Код закрытия от сервера; 1006 — соединение оборвано без close-фрейма */
    public ?int $closeCode = null;

    /** Получен ли pong после последнего ping() */
    public bool $ponged = false;

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $this->socket = stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $errno, $error, 2);
        if ($this->socket === false) {
            throw new RuntimeException("WS connect failed: $error");
        }

        $key = base64_encode(random_bytes(16));
        // Сервер сверх лимита соединений закрывает сокет сразу — запись может не пройти
        @fwrite($this->socket,
            'GET ' . ($parts['path'] ?? '/') . " HTTP/1.1\r\n"
            . "Host: {$parts['host']}:{$parts['port']}\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n"
        );

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = $this->read(2);
            if ($chunk === null || $chunk === '') {
                throw new RuntimeException('WS handshake failed: ' . $response);
            }
            $response .= $chunk;
        }
        [$headers, $this->in] = explode("\r\n\r\n", $response, 2);

        if (!str_starts_with($headers, 'HTTP/1.1 101')
            || !str_contains($headers, 'Sec-WebSocket-Accept: ' . WsFrame::acceptKey($key))) {
            throw new RuntimeException('WS handshake rejected: ' . $headers);
        }
    }

    /** Сообщение протокола; пустой body уходит как {} */
    public function send(string $group, string $event, array $body = [], ?string $uid = null): void
    {
        $this->sendText(json_encode(['uid' => $uid ?? WsMessage::uuid(), 'group' => $group, 'event' => $event, 'body' => (object) $body]));
    }

    public function sendText(string $text): void
    {
        $this->sendFrame(WsFrame::OP_TEXT, $text);
    }

    public function sendFrame(int $opcode, string $payload = '', bool $fin = true): void
    {
        $frame = WsFrame::encode($opcode, $payload, true);
        if (!$fin) {
            $frame[0] = chr(ord($frame[0]) & 0x7F);
        }
        @fwrite($this->socket, $frame);
    }

    /** Подтверждает получение события */
    public function ack(array $event): void
    {
        $this->send('events', 'received', ['receivedUid' => $event['uid']]);
    }

    /** Отправляет ping и ждёт pong. */
    public function ping(float $timeout = 2): bool
    {
        $this->ponged = false;
        $this->sendFrame(WsFrame::OP_PING, 'hb');
        $deadline = microtime(true) + $timeout;
        while (!$this->ponged && $this->closeCode === null && microtime(true) < $deadline) {
            $this->pump($deadline - microtime(true));
        }

        return $this->ponged;
    }

    /**
     * Следующее сообщение сервера (декодированный JSON). null — сервер закрыл соединение
     * (код в $closeCode) или за $timeout ничего не пришло.
     */
    public function receive(float $timeout = 2): ?array
    {
        $deadline = microtime(true) + $timeout;
        while ($this->closeCode === null) {
            $message = $this->pump(max(0, $deadline - microtime(true)));
            if ($message !== null) {
                return $message;
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
        }

        return null;
    }

    /** Закрывает соединение со стороны клиента */
    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            if ($this->closeCode === null) {
                $this->sendFrame(WsFrame::OP_CLOSE, WsFrame::closePayload(1000));
            }
            fclose($this->socket);
        }
    }

    /** Обрабатывает один фрейм из буфера (дочитывая сокет не дольше $timeout); текстовый — возвращает. */
    private function pump(float $timeout): ?array
    {
        $frame = WsFrame::decode($this->in, false, PHP_INT_MAX);
        if ($frame === null) {
            $chunk = $this->read($timeout);
            if ($chunk === '') {
                $this->closeCode ??= 1006;
            } elseif ($chunk !== null) {
                $this->in .= $chunk;
            }
            return null;
        }

        switch ($frame['opcode']) {
            case WsFrame::OP_TEXT:
                return json_decode($frame['payload'], true);
            case WsFrame::OP_PING:
                $this->sendFrame(WsFrame::OP_PONG, $frame['payload']);
                return null;
            case WsFrame::OP_PONG:
                $this->ponged = true;
                return null;
            case WsFrame::OP_CLOSE:
                $this->closeCode = WsFrame::closeCode($frame['payload']) ?? 1005;
                $this->sendFrame(WsFrame::OP_CLOSE, $frame['payload']);
                return null;
        }

        return null;
    }

    /** Данные из сокета: null — таймаут, '' — соединение закрыто */
    private function read(float $timeout): ?string
    {
        $read = [$this->socket];
        $write = $except = null;
        if (!@stream_select($read, $write, $except, (int) $timeout, (int) (fmod($timeout, 1) * 1_000_000))) {
            return null;
        }
        $data = @fread($this->socket, 65536);

        return $data === false ? '' : $data;
    }
}
