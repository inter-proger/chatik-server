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

        $userId = self::currentUserId();
        if ($userId === null) {
            Response::error(ErrorCode::NOT_AUTHENTICATED);
        }

        return $userId;
    }

    /**
     * id авторизованного пользователя или null. Для авторизованного продлевает сессию.
     * Без cookie сессии сессию не стартует — чтобы не создавать пустых сессий для анонимов.
     */
    public static function currentUserId(): ?int
    {
        if (!isset($_COOKIE[session_name()])) {
            return null;
        }
        Session::start();

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $userId = (int) $_SESSION['user_id'];

        $stmt = DB::get()->prepare('SELECT 1 FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            // Сессия ссылается на удалённого пользователя — сбрасываем её
            $_SESSION = [];
            return null;
        }

        return $userId;
    }
}
