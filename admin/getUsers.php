<?php

require_once __DIR__ . '/../core/Admin.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

const ADMIN_USERS_COUNT = 50;

Admin::require();

$query = $_REQUEST['query'] ?? '';
if (!is_string($query)) {
    Response::error(ErrorCode::INVALID_PARAMS);
}
$query = trim(mb_scrub($query, 'UTF-8'));

// Берём на одного больше, чтобы узнать, есть ли ещё совпадения
$users   = User::findList($query !== '' ? mb_substr($query, 0, 255) : null, null, ADMIN_USERS_COUNT + 1);
$hasNext = count($users) > ADMIN_USERS_COUNT;

Response::success([
    'users'   => array_slice($users, 0, ADMIN_USERS_COUNT),
    'hasNext' => $hasNext,
]);
