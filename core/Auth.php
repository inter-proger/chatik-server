<?php

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/ErrorCode.php';

class Auth
{
    /**
     * Стартует сессию и возвращает id авторизованного пользователя.
     * Если пользователь не авторизован — отдаёт NOT_AUTHENTICATED и завершает запрос.
     */
    public static function requireUserId(): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_id'])) {
            Response::error(ErrorCode::NOT_AUTHENTICATED);
        }

        return (int) $_SESSION['user_id'];
    }
}
