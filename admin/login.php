<?php

require_once __DIR__ . '/../core/Admin.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$login    = $_POST['login'] ?? '';
$password = $_POST['password'] ?? '';

if (!is_string($login) || !is_string($password) || !Admin::checkCredentials($login, $password)) {
    Response::error(ErrorCode::WRONG_CREDENTIALS);
}

Admin::startSession();
session_regenerate_id(true);
$_SESSION['is_admin'] = true;

Response::success();
