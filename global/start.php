<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/WebSocket.php';
require_once __DIR__ . '/../core/Response.php';

// Доступен без авторизации: аноним получает websocketConnectInfo = null
$userId = Auth::currentUserId();

// В ответе токен — не кэшировать
header('Cache-Control: no-store');

Response::success([
    'websocketConnectInfo' => $userId !== null ? WebSocket::issueToken($userId, session_id()) : null,
]);
