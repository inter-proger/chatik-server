<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Message.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$blockId = $_REQUEST['blockId'] ?? '';

if (!is_string($blockId) || !ctype_digit($blockId)) {
    Response::error(ErrorCode::MESSAGE_BLOCK_NOT_FOUND);
}
$blockId = (int) $blockId;

// Блок чужой переписки неотличим от несуществующего
$conversationId = Message::findBlockConversationId($blockId);
if ($conversationId === null || !Conversation::isMember($conversationId, $userId)) {
    Response::error(ErrorCode::MESSAGE_BLOCK_NOT_FOUND);
}

$messages = Message::findByBlock($blockId);

Response::success([
    'messages' => $messages,
], [
    Action::users(array_column($messages, 'senderId')),
]);
