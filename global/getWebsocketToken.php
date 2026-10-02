<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/WebSocket.php';
require_once __DIR__ . '/../core/Response.php';

$userId = Auth::requireUserId();

// В ответе токен — не кэшировать
header('Cache-Control: no-store');

Response::success([
    'websocketConnectInfo' => WebSocket::issueToken($userId, session_id()),
]);
