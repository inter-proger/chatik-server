<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/ErrorCode.php';

class Auth
{
    /**
     * Стартует сессию и возвращает id авторизованного пользователя.
     * Если пользователь не авторизован или уже удалён — отдаёт NOT_AUTHENTICATED и завершает запрос.
     */
    public static function requireUserId(): int
    {
        Session::start();

        if (empty($_SESSION['user_id'])) {
            Response::error(ErrorCode::NOT_AUTHENTICATED);
        }

        $userId = (int) $_SESSION['user_id'];

        $stmt = DB::get()->prepare('SELECT 1 FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            // Сессия ссылается на удалённого пользователя — сбрасываем её
            $_SESSION = [];
            Response::error(ErrorCode::NOT_AUTHENTICATED);
        }

        return $userId;
    }
}
