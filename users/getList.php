<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

const USERS_DEFAULT_COUNT = 30;
const USERS_MAX_COUNT     = 100;

Auth::requireUserId();

$query = $_REQUEST['query'] ?? '';
$maxId = $_REQUEST['maxId'] ?? '';
$count = $_REQUEST['count'] ?? '';

// Параметры вида query[]=... приходят массивами
if (!is_string($query) || !is_string($maxId) || !is_string($count)) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

// Невалидные UTF-8 байты заменяем, иначе MySQL отклонит строку
$query = trim(mb_scrub($query, 'UTF-8'));

if ($maxId !== '' && !ctype_digit($maxId)) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

if ($count !== '' && (!ctype_digit($count) || (int) $count <= 0)) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

$count = $count === '' ? USERS_DEFAULT_COUNT : min((int) $count, USERS_MAX_COUNT);

// Берём на одного больше, чтобы узнать, есть ли следующая страница
$users = User::findList(
    $query !== '' ? mb_substr($query, 0, 255) : null,
    $maxId !== '' ? (int) $maxId : null,
    $count + 1
);
$hasNext = count($users) > $count;
$users   = array_slice($users, 0, $count);

Response::success([
    'users'     => $users,
    'nextMaxId' => $users ? end($users)['userId'] : null,
    'hasNext'   => $hasNext,
]);
