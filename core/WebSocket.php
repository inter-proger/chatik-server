<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/../config/local.php';
require_once LOCAL_CONFIG_DIR . '/app.php';
require_once __DIR__ . '/../config/websocket.php';

/**
 * WebSocket со стороны API: токены подключения и вызовы WS-сервера (websocket/server.php).
 * Протокол — docs/api/WebSocket.md; устройство — WebSocket_Architecture.md.
 */
class WebSocket
{
    /** Код закрытия соединений завершённой сессии */
    const CLOSE_SESSION_ENDED = 4003;

    /**
     * Выдаёт одноразовый токен подключения для пользователя и сессии.
     * Возвращает объект WebsocketConnectInfo: url, token, expiresIn.
     */
    public static function issueToken(int $userId, string $sessionId): array
    {
        $db = DB::get();

        // Истёкшие токены чистим попутно — отдельный крон не нужен
        $db->exec('DELETE FROM websocket_tokens WHERE expires_at < NOW()');

        $token = bin2hex(random_bytes(32));
        $stmt  = $db->prepare(
            'INSERT INTO websocket_tokens (token_hash, user_id, session_id, expires_at)
             VALUES (?, ?, ?, NOW() + INTERVAL ? SECOND)'
        );
        $stmt->execute([hash('sha256', $token), $userId, $sessionId, WEBSOCKET_TOKEN_TTL]);

        return [
            'url'       => WEBSOCKET_URL,
            'token'     => $token,
            'expiresIn' => WEBSOCKET_TOKEN_TTL,
        ];
    }

    /**
     * Гасит токен и возвращает id сессии, для которой он выдан, или null, если токен
     * не найден, истёк, уже использован или выдан другому пользователю.
     * Токен гасится при любой попытке, в том числе неуспешной.
     */
    public static function consumeToken(string $token, int $userId): ?string
    {
        $db   = DB::get();
        $hash = hash('sha256', $token);

        $stmt = $db->prepare(
            'SELECT id, user_id, session_id, expires_at > NOW() AS is_valid FROM websocket_tokens WHERE token_hash = ?'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        // Токен достаётся только тому, кто его удалил: параллельная попытка получит rowCount 0
        $stmt = $db->prepare('DELETE FROM websocket_tokens WHERE id = ?');
        $stmt->execute([$row['id']]);
        if ($stmt->rowCount() !== 1 || !$row['is_valid'] || (int) $row['user_id'] !== $userId) {
            return null;
        }

        return $row['session_id'];
    }

    /**
     * Завершение сессии (выход, повторный вход, регистрация поверх старой сессии):
     * отзывает её токены и закрывает её соединения с кодом 4003.
     */
    public static function endSession(string $sessionId): void
    {
        DB::get()->prepare('DELETE FROM websocket_tokens WHERE session_id = ?')->execute([$sessionId]);

        self::internalRequest('/closeSession', ['sessionId' => $sessionId, 'code' => self::CLOSE_SESSION_ENDED]);
    }

    /** Отправляет одно и то же событие во все соединения пользователей $recipientIds. */
    public static function publish(array $recipientIds, string $group, string $event, array $body, array $userIds = []): void
    {
        self::publishEach([self::delivery($recipientIds, $group, $event, $body, $userIds)]);
    }

    /**
     * Отправляет несколько доставок одним запросом — когда содержимое события зависит
     * от получателя. Доставки собираются через delivery().
     */
    public static function publishEach(array $deliveries): void
    {
        $deliveries = array_values(array_filter($deliveries, fn($d) => $d['recipientIds']));
        if ($deliveries) {
            self::internalRequest('/publish', ['deliveries' => $deliveries]);
        }
    }

    /** Доставка для publishEach(): получатели и сообщение (без uid — его выдаёт WS-сервер). */
    public static function delivery(array $recipientIds, string $group, string $event, array $body, array $userIds = []): array
    {
        return [
            'recipientIds' => self::normalizeIds($recipientIds),
            'message'      => self::message($group, $event, $body, $userIds),
        ];
    }

    /**
     * Сообщение события без uid: group, event, body и userIds (только если список не пуст).
     * Пустой body сериализуется в {}.
     */
    public static function message(string $group, string $event, array $body, array $userIds = []): array
    {
        $message = ['group' => $group, 'event' => $event, 'body' => (object) $body];

        $userIds = self::normalizeIds($userIds);
        if ($userIds) {
            $message['userIds'] = $userIds;
        }

        return $message;
    }

    /** id без повторов, по возрастанию */
    private static function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * POST во внутренний эндпоинт WS-сервера. Короткие таймауты: доставка не гарантирована,
     * поэтому неудача только логируется и не роняет REST-запрос.
     */
    private static function internalRequest(string $path, array $payload): bool
    {
        if (!defined('WEBSOCKET_INTERNAL_SECRET')) {
            error_log("WebSocket: $path skipped: WEBSOCKET_INTERNAL_SECRET is not configured");
            return false;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $socket = @stream_socket_client(
            'tcp://127.0.0.1:' . WEBSOCKET_INTERNAL_PORT, $errno, $error, WEBSOCKET_INTERNAL_CONNECT_TIMEOUT
        );
        if ($socket === false) {
            error_log("WebSocket: $path failed: $error");
            return false;
        }

        stream_set_timeout($socket, 0, (int) (WEBSOCKET_INTERNAL_TIMEOUT * 1_000_000));
        fwrite($socket,
            "POST $path HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Content-Type: application/json\r\n"
            . 'X-Internal-Secret: ' . WEBSOCKET_INTERNAL_SECRET . "\r\n"
            . 'Content-Length: ' . strlen($json) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $json
        );
        $status = fgets($socket);
        $timedOut = stream_get_meta_data($socket)['timed_out'];
        fclose($socket);

        if ($status === false || !preg_match('#^HTTP/1\.1 200 #', $status)) {
            error_log("WebSocket: $path failed: " . ($timedOut ? 'timeout' : trim((string) $status)));
            return false;
        }

        return true;
    }
}
