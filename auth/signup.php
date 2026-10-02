<?php

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../core/ErrorCode.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/WebSocket.php';

// Максимальные длины соответствуют колонкам users.login и users.name
const LOGIN_MAX_LENGTH = 64;
const NAME_MAX_LENGTH  = 128;

$login    = trim($_POST['login']    ?? '');
$password = trim($_POST['password'] ?? '');
$name     = trim($_POST['name']     ?? '');

if (!preg_match('/^\w+$/', $login) || strlen($login) > LOGIN_MAX_LENGTH) {
    Response::error(ErrorCode::INVALID_LOGIN);
}

if (
    strlen($password) < 8 ||
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[a-z]/', $password) ||
    !preg_match('/[0-9]/', $password)
) {
    Response::error(ErrorCode::INVALID_PASSWORD);
}

if (!preg_match('/^[\p{L}0-9\-_ ]+$/u', $name) || mb_strlen($name) > NAME_MAX_LENGTH) {
    Response::error(ErrorCode::INVALID_NAME);
}

$db = DB::get();

// Занятость логина проверяет UNIQUE-ключ users.login — так нет гонки между проверкой и вставкой
$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $db->prepare('INSERT INTO users (login, password_hash, name) VALUES (?, ?, ?)');
try {
    $stmt->execute([$login, $hash, $name]);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? null) === 1062) {
        Response::error(ErrorCode::LOGIN_TAKEN);
    }
    throw $e;
}
$userId = (int) $db->lastInsertId();

Session::start();
// Старая сессия уничтожается — отзываем её токены и закрываем её WS-соединения
$oldSessionId = !empty($_SESSION['user_id']) ? session_id() : null;
session_regenerate_id(true);
if ($oldSessionId !== null) {
    WebSocket::endSession($oldSessionId);
}
$_SESSION['user_id'] = $userId;
$_SESSION['name']    = $name;

Response::success(User::findById($userId));
