<?php

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../core/ErrorCode.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/WebSocket.php';

$login    = trim($_POST['login']    ?? '');
$password = trim($_POST['password'] ?? '');

if (!preg_match('/^\w+$/', $login)) {
    Response::error(ErrorCode::INVALID_LOGIN);
}

if (strlen($password) < 6) {
    Response::error(ErrorCode::INVALID_PASSWORD);
}

$db = DB::get();
$stmt = $db->prepare('SELECT id, password_hash, name FROM users WHERE login = ?');
$stmt->execute([$login]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    Response::error(ErrorCode::WRONG_CREDENTIALS);
}

Session::start();
// Старая сессия уничтожается — отзываем её токены и закрываем её WS-соединения
$oldSessionId = !empty($_SESSION['user_id']) ? session_id() : null;
session_regenerate_id(true);
if ($oldSessionId !== null) {
    WebSocket::endSession($oldSessionId);
}
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['name']    = $user['name'];

Response::success(User::findById((int) $user['id']));
