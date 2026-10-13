<?php

require_once __DIR__ . '/../config/local.php';
require_once LOCAL_CONFIG_DIR . '/db.php';

class DB
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Колонки времени — DATETIME без часового пояса, хранятся в UTC: от time_zone соединения
            // зависят CURRENT_TIMESTAMP, NOW() и UNIX_TIMESTAMP()
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    /** SQL-выражение: колонка времени $column как Unix timestamp в миллисекундах (так время отдаётся в API). */
    public static function ms(string $column): string
    {
        return "CAST(UNIX_TIMESTAMP($column) * 1000 AS UNSIGNED)";
    }

    /**
     * SQL-выражение: параметр ? (Unix timestamp в миллисекундах) как DATETIME — для сравнения с колонками времени.
     * Умножение в DECIMAL, а не деление: точность деления зависит от div_precision_increment
     * (при 0 миллисекунды отбрасываются), у умножения на 0.001 — всегда 3 знака; не DOUBLE,
     * т. к. параметры PDO приходят строками.
     */
    public static function fromMs(): string
    {
        return 'FROM_UNIXTIME(CAST(? AS UNSIGNED) * 0.001)';
    }

    /**
     * Сбрасывает закэшированное соединение — следующий get() подключится заново.
     * Нужно долгоживущим процессам (websocket/server.php), когда MySQL закрыл соединение по wait_timeout.
     */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** Ошибка «MySQL server has gone away» / «Lost connection» — соединение надо пересоздать. */
    public static function isConnectionLost(PDOException $e): bool
    {
        return in_array($e->errorInfo[1] ?? null, [2006, 2013], true)
            || str_contains($e->getMessage(), 'server has gone away');
    }
}
