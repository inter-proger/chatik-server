<?php

require_once __DIR__ . '/../core/Admin.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

const ADMIN_CONVERSATIONS_DEFAULT_COUNT = 30;
const ADMIN_CONVERSATIONS_MAX_COUNT     = 100;

Admin::require();

$userId = $_REQUEST['userId'] ?? '';
$offset = $_REQUEST['offset'] ?? '';
$count  = $_REQUEST['count'] ?? '';

if (!is_string($userId) || !ctype_digit($userId) || User::findById((int) $userId) === null) {
    Response::error(ErrorCode::USER_NOT_FOUND);
}

if (!is_string($offset) || !is_string($count)
    || ($offset !== '' && !ctype_digit($offset))
    || ($count !== '' && (!ctype_digit($count) || (int) $count <= 0))) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

$offset = $offset === '' ? 0 : (int) $offset;
$count  = $count === '' ? ADMIN_CONVERSATIONS_DEFAULT_COUNT : min((int) $count, ADMIN_CONVERSATIONS_MAX_COUNT);

// Берём на одну больше, чтобы узнать, есть ли следующая страница
$conversations = Conversation::findListByMember((int) $userId, $offset, $count + 1);
$hasNext = count($conversations) > $count;
$conversations = array_slice($conversations, 0, $count);

// Участники сразу объектами — странице нужны имена собеседников
$users = User::findByIds(array_merge([], ...array_column($conversations, 'memberIds')));
ksort($users);

Response::success([
    'conversations' => $conversations,
    'users'         => array_values($users),
    'hasNext'       => $hasNext,
]);
