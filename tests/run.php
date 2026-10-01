<?php

/**
 * Запуск тестов: php tests/run.php [фильтр]
 *
 * Пересоздаёт тестовую БД (tests/config/db.php) из миграций db/*.sql, поднимает встроенный
 * PHP-сервер с тестовым конфигом и прогоняет все классы *Test из tests/unit и tests/api.
 * Фильтр — подстрока в имени "Класс::метод".
 */

$root = dirname(__DIR__);
putenv('SERVERAI_CONFIG_DIR=' . __DIR__ . '/config');

require_once __DIR__ . '/lib/TestCase.php';

$filter = $argv[1] ?? '';

// ---- База ----

if (!str_ends_with(DB_NAME, '_test')) {
    fwrite(STDERR, "Refusing to use database '" . DB_NAME . "': test database name must end with _test\n");
    exit(1);
}

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
$server->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4');
$server->exec('USE `' . DB_NAME . '`');
$migrations = glob("$root/db/*.sql");
sort($migrations);
foreach ($migrations as $migration) {
    $server->exec(file_get_contents($migration));
}
$server = null;

// ---- HTTP-сервер ----

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);

$serverLog = tempnam(sys_get_temp_dir(), 'serverai-test-log-');
$process = proc_open(
    [
        PHP_BINARY,
        '-d', 'upload_max_filesize=12M',
        '-d', 'post_max_size=16M',
        '-d', 'memory_limit=256M',
        '-S', "127.0.0.1:$port",
        'index.php',
    ],
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    $root,
    getenv() + ['PHP_CLI_SERVER_WORKERS' => '1']
);

register_shutdown_function(function () use ($process) {
    proc_terminate($process);
    proc_close($process);
});

for ($i = 0; $i < 100; $i++) {
    if ($conn = @fsockopen('127.0.0.1', $port)) {
        fclose($conn);
        break;
    }
    usleep(50_000);
}
TestCase::$baseUrl = "http://127.0.0.1:$port";

// ---- Тесты ----

$files = array_merge(glob(__DIR__ . '/unit/*Test.php'), glob(__DIR__ . '/api/*Test.php'));
foreach ($files as $file) {
    require_once $file;
}

$passed   = 0;
$failures = [];

foreach (get_declared_classes() as $class) {
    if (!is_subclass_of($class, TestCase::class) || (new ReflectionClass($class))->isAbstract()) {
        continue;
    }

    foreach (get_class_methods($class) as $method) {
        $name = "$class::$method";
        if (!str_starts_with($method, 'test') || ($filter !== '' && !str_contains($name, $filter))) {
            continue;
        }

        $test = new $class();
        try {
            $test->setUp();
            $test->$method();
            $passed++;
            echo '.';
        } catch (Throwable $e) {
            $failures[] = [$name, $e];
            echo $e instanceof AssertionFailed ? 'F' : 'E';
        } finally {
            $test->tearDown();
        }
    }
}

// Убираем файлы, загруженные последним тестом
TestCase::resetDatabase();

echo "\n\n";

foreach ($failures as $i => [$name, $e]) {
    echo ($i + 1) . ") $name\n";
    if ($e instanceof AssertionFailed) {
        // Строка теста, где упала проверка
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && !str_contains($frame['file'], '/tests/lib/')) {
                echo '   at ' . substr($frame['file'], strlen($root) + 1) . ':' . $frame['line'] . "\n";
                break;
            }
        }
        echo '   ' . str_replace("\n", "\n   ", $e->getMessage()) . "\n\n";
    } else {
        echo '   ' . str_replace("\n", "\n   ", (string) $e) . "\n\n";
    }
}

$log = trim(file_get_contents($serverLog));
@unlink($serverLog);
// Встроенный сервер пишет в лог каждый запрос; интересны только сообщения PHP.
// Предупреждения показываем (часть из них ожидаема — например, от битых картинок), фатальные ошибки валят прогон
$messages = array_filter(explode("\n", $log), fn($line) => str_contains($line, 'PHP ') || str_contains($line, 'Stack trace'));
if ($messages) {
    echo "Server log:\n" . implode("\n", $messages) . "\n\n";
}
$errors = array_filter($messages, fn($line) => preg_match('/PHP Fatal|Uncaught/', $line));

$total = $passed + count($failures);
echo $failures ? "FAILED: $passed/$total passed\n" : "OK ($total tests)\n";
exit($failures || $errors ? 1 : 0);
