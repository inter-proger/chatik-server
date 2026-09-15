<?php

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';
require_once __DIR__ . '/../core/User.php';

$login    = trim($_POST['login']    ?? '');
$password = trim($_POST['password'] ?? '');
$name     = trim($_POST['name']     ?? '');

if (!preg_match('/^\w+$/', $login)) {
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

if (!preg_match('/^[\p{L}0-9\-_ ]+$/u', $name) || $name === '') {
    Response::error(ErrorCode::INVALID_NAME);
}

$db = DB::get();

$stmt = $db->prepare('SELECT id FROM users WHERE login = ?');
$stmt->execute([$login]);
if ($stmt->fetch()) {
    Response::error(ErrorCode::LOGIN_TAKEN);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $db->prepare('INSERT INTO users (login, password_hash, name) VALUES (?, ?, ?)');
$stmt->execute([$login, $hash, $name]);
$userId = (int) $db->lastInsertId();

session_start();
session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
$_SESSION['name']    = $name;

Response::success(User::findById($userId));
