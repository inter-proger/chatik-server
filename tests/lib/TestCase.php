<?php

require_once __DIR__ . '/AssertionFailed.php';
require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Fixtures.php';
require_once __DIR__ . '/WsClient.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/File.php';
require_once __DIR__ . '/../../core/Conversation.php';

/**
 * Базовый класс тестов. Перед каждым тестом база очищается,
 * а файлы, загруженные в предыдущем тесте, удаляются с диска.
 */
abstract class TestCase
{
    /** Адрес тестового сервера, выставляется раннером */
    public static string $baseUrl = '';

    /** Таймауты тестового WS-сервера, секунд, и его лимиты (передаются ему раннером) */
    const WS_AUTH_TIMEOUT      = 1;
    const WS_IDLE_TIMEOUT      = 2;
    const WS_MAX_CONNECTIONS   = 10;
    const WS_MAX_OUTPUT_BUFFER = 64 * 1024;

    /** @var WsClient[] открытые в тесте WS-соединения — закрываются в tearDown */
    private array $wsClients = [];

    public function setUp(): void
    {
        self::resetDatabase();
    }

    public function tearDown(): void
    {
        foreach ($this->wsClients as $ws) {
            $ws->disconnect();
        }
        $this->wsClients = [];
    }

    public static function resetDatabase(): void
    {
        $db = DB::get();

        // Файлы на диске, на которые ссылаются записи, — иначе они копились бы в static/uploads
        foreach ($db->query('SELECT path, data FROM files')->fetchAll() as $row) {
            $data = json_decode($row['data'], true) ?: [];
            foreach ([$row['path'], $data['small_path'] ?? null] as $path) {
                if ($path !== null && str_starts_with($path, UPLOAD_DIR . '/')) {
                    @unlink(dirname(__DIR__, 2) . '/' . $path);
                }
            }
        }

        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['websocket_tokens', 'messages', 'message_blocks', 'conversation_removed_members', 'conversation_members', 'conversations', 'files', 'users'] as $table) {
            $db->exec("TRUNCATE TABLE $table");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ---- Фабрики ----

    protected function client(): ApiClient
    {
        return new ApiClient(self::$baseUrl);
    }

    /** Регистрирует пользователя через API и возвращает залогиненный клиент и сущность User. */
    protected function signup(string $login, string $name = 'Test User', string $password = 'Passw0rd'): array
    {
        $client = $this->client();
        $res = $client->post('/auth/signup', ['login' => $login, 'password' => $password, 'name' => $name]);
        $this->assertSuccess($res);

        return [$client, $res['response']];
    }

    /** Создаёт пользователя напрямую в БД, минуя API (быстрее — без bcrypt по умолчанию). */
    protected function insertUser(string $login, ?string $name = null, string $password = 'Passw0rd'): int
    {
        $stmt = DB::get()->prepare('INSERT INTO users (login, password_hash, name) VALUES (?, ?, ?)');
        $stmt->execute([$login, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $name ?? $login]);

        return (int) DB::get()->lastInsertId();
    }

    protected function insertFile(int $userId, string $type = 'image', array $data = ['small_path' => 'static/uploads/x_small.png']): int
    {
        $stmt = DB::get()->prepare(
            'INSERT INTO files (user_id, type, path, size, filename, extension, data) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, 'static/uploads/x.png', 123, 'x.png', 'png', json_encode($data)]);

        return (int) DB::get()->lastInsertId();
    }

    /** Открывает WS-соединение (без авторизации). */
    protected function ws(): WsClient
    {
        return $this->wsClients[] = new WsClient(WEBSOCKET_URL);
    }

    /** Токен подключения через /global/getWebsocketToken */
    protected function wsToken(ApiClient $client): string
    {
        $res = $client->get('/global/getWebsocketToken');
        $this->assertSuccess($res);

        return $res['response']['websocketConnectInfo']['token'];
    }

    /** Открывает WS-соединение и авторизует его токеном пользователя клиента. */
    protected function wsConnect(ApiClient $client, int $userId): WsClient
    {
        $ws = $this->ws();
        $ws->send('auth', 'connect', ['userId' => $userId, 'token' => $this->wsToken($client)]);
        $this->assertEvent('auth', 'connected', [], $ws->receive());

        return $ws;
    }

    // ---- Проверки ----

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? "$message\n" : '')
                . 'Expected: ' . self::export($expected) . "\nActual:   " . self::export($actual));
        }
    }

    protected function assertTrue(mixed $value, string $message = 'Expected true'): void
    {
        $this->assertSame(true, $value, $message);
    }

    protected function assertFalse(mixed $value, string $message = 'Expected false'): void
    {
        $this->assertSame(false, $value, $message);
    }

    protected function assertNull(mixed $value, string $message = 'Expected null'): void
    {
        $this->assertSame(null, $value, $message);
    }

    protected function assertCount(int $expected, array $items, string $message = ''): void
    {
        $this->assertSame($expected, count($items), $message !== '' ? $message : 'Unexpected count');
    }

    protected function assertStringStartsWith(string $prefix, string $actual): void
    {
        if (!str_starts_with($actual, $prefix)) {
            $this->fail('Expected ' . self::export($actual) . ' to start with ' . self::export($prefix));
        }
    }

    protected function assertSuccess(array $res): void
    {
        if (($res['status'] ?? null) !== 1) {
            $this->fail('Expected success, got: ' . self::export($res));
        }
    }

    protected function assertError(int $code, array $res): void
    {
        if (($res['status'] ?? null) !== 0 || ($res['errorCode'] ?? null) !== $code) {
            $this->fail("Expected error $code, got: " . self::export($res));
        }
    }

    /** В ответе ровно один action — users с указанными id (по возрастанию). */
    protected function assertUsersAction(array $userIds, array $res): void
    {
        sort($userIds);
        $this->assertSame([['type' => 'users', 'data' => ['userIds' => $userIds]]], $res['actions'] ?? null, 'Unexpected actions');
    }

    /** Сообщение сервера — событие group / event с указанным body (и userIds, если передан). */
    protected function assertEvent(string $group, string $event, array $body, ?array $message, ?array $userIds = null): void
    {
        $this->assertTrue(is_array($message), 'Expected event ' . "$group/$event" . ', got nothing');
        $this->assertTrue(is_string($message['uid'] ?? null) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $message['uid']) === 1, 'Event uid is not UUID v4');
        $expected = ['group' => $group, 'event' => $event, 'body' => $body] + ($userIds !== null ? ['userIds' => $userIds] : []);
        unset($message['uid']);
        $this->assertSame($expected, $message, 'Unexpected event');
    }

    /**
     * Счётчики непрочитанных согласованы с указателями прочтения, а общие — с суммой по перепискам:
     * пересчёт (Conversation::recountUnread) ничего не меняет.
     */
    protected function assertUnreadInvariant(): void
    {
        $snapshot = fn() => [
            DB::get()->query('SELECT conversation_id, user_id, unread_count FROM conversation_members ORDER BY conversation_id, user_id')->fetchAll(),
            DB::get()->query('SELECT id, total_unread_count FROM users ORDER BY id')->fetchAll(),
        ];

        $before = $snapshot();
        Conversation::recountUnread();
        $this->assertSame($before, $snapshot(), 'Unread counters are out of sync');
    }

    /** Сервер закрыл соединение с кодом $code. */
    protected function assertClosed(int $code, WsClient $ws): void
    {
        $message = $ws->receive();
        $this->assertNull($message, 'Expected close, got message: ' . self::export($message));
        $this->assertSame($code, $ws->closeCode, 'Unexpected close code');
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    private static function export(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
