<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$user = User::findById($userId);

if ($user === null) {
    // Сессия ссылается на несуществующего пользователя — считаем неавторизованным
    Response::error(ErrorCode::NOT_AUTHENTICATED);
}

Response::success($user);
