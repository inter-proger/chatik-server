<?php

require_once __DIR__ . '/AssertionFailed.php';
require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Fixtures.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/File.php';

/**
 * Базовый класс тестов. Перед каждым тестом база очищается,
 * а файлы, загруженные в предыдущем тесте, удаляются с диска.
 */
abstract class TestCase
{
    /** Адрес тестового сервера, выставляется раннером */
    public static string $baseUrl = '';

    public function setUp(): void
    {
        self::resetDatabase();
    }

    public function tearDown(): void
    {
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
        foreach (['conversation_members', 'conversations', 'files', 'users'] as $table) {
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

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    private static function export(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
