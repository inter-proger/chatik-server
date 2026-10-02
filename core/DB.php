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
        }
        return self::$pdo;
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
