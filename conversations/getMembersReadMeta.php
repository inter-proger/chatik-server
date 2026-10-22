<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$conversationId = $_REQUEST['conversationId'] ?? '';

if (!is_string($conversationId) || !ctype_digit($conversationId)
    || !Conversation::isMember((int) $conversationId, $userId)) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}

$membersReadMeta = Conversation::findMembersReadMeta((int) $conversationId, $userId);

// Состава группы в Conversation нет — участников отдаём action users
Response::success([
    'membersReadMeta' => $membersReadMeta,
], [
    Action::users(array_column($membersReadMeta, 'userId')),
]);
