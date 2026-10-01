<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

const CONVERSATIONS_DEFAULT_COUNT = 30;
const CONVERSATIONS_MAX_COUNT     = 100;

$userId = Auth::requireUserId();

$offset = $_REQUEST['offset'] ?? '';
$count  = $_REQUEST['count'] ?? '';

if ($offset !== '' && !ctype_digit((string) $offset)) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

if ($count !== '' && (!ctype_digit((string) $count) || (int) $count <= 0)) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

$offset = $offset === '' ? 0 : (int) $offset;
$count  = $count === '' ? CONVERSATIONS_DEFAULT_COUNT : min((int) $count, CONVERSATIONS_MAX_COUNT);

// Берём на одну больше, чтобы узнать, есть ли следующая страница
$conversations = Conversation::findListByMember($userId, $offset, $count + 1);
$hasNext = count($conversations) > $count;
$conversations = array_slice($conversations, 0, $count);

Response::success([
    'conversations' => $conversations,
    'hasNext'       => $hasNext,
], [
    Action::users(array_merge([], ...array_column($conversations, 'memberIds'))),
]);
