<?php

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/ErrorCode.php';

/**
 * Админ-панель (/admin): вход и проверка доступа.
 * Сессия админа — отдельная cookie ADMINSESSID, не пересекается с сессией пользователя.
 */
class Admin
{
    // Временно захардкожены — заменить на настройку из config/app.php
    private const LOGIN    = 'admin';
    private const PASSWORD = 'admin';

    const SESSION_NAME = 'ADMINSESSID';

    public static function checkCredentials(string $login, string $password): bool
    {
        // Обе проверки выполняются всегда — время ответа не выдаёт, совпал ли логин
        $loginOk    = hash_equals(self::LOGIN, $login);
        $passwordOk = hash_equals(self::PASSWORD, $password);

        return $loginOk && $passwordOk;
    }

    public static function startSession(): void
    {
        session_name(self::SESSION_NAME);
        Session::start();
    }

    /** Если админ не вошёл — отдаёт NOT_AUTHENTICATED и завершает запрос. */
    public static function require(): void
    {
        if (!isset($_COOKIE[self::SESSION_NAME])) {
            Response::error(ErrorCode::NOT_AUTHENTICATED);
        }
        self::startSession();

        if (empty($_SESSION['is_admin'])) {
            Response::error(ErrorCode::NOT_AUTHENTICATED);
        }
    }
}
