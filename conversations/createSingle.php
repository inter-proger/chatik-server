<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$otherUserId = $_POST['userId'] ?? '';

if (!ctype_digit((string) $otherUserId) || (int) $otherUserId <= 0) {
    Response::error(ErrorCode::USER_NOT_FOUND);
}
$otherUserId = (int) $otherUserId;

if ($otherUserId === $userId) {
    Response::error(ErrorCode::CONVERSATION_WITH_SELF);
}

if (User::findById($otherUserId) === null) {
    Response::error(ErrorCode::USER_NOT_FOUND);
}

Response::success([
    'conversation' => Conversation::findOrCreateSingle($userId, $otherUserId),
]);
