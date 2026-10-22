<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$conversationId = $_REQUEST['conversationId'] ?? '';

if (!is_string($conversationId) || !ctype_digit($conversationId)
    || !Conversation::isMember((int) $conversationId, $userId)) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}

// Action users не нужен: все userId — участники переписки, клиент знает их из memberIds
Response::success([
    'membersReadMeta' => Conversation::findMembersReadMeta((int) $conversationId, $userId),
]);
