<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Message.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

/** Отдаём целые блоки, пока сообщений не наберётся хотя бы столько */
const MESSAGES_LIST_MIN_COUNT = 40;

$userId = Auth::requireUserId();

$conversationId = $_REQUEST['conversationId'] ?? '';
$blockId        = $_REQUEST['blockId'] ?? '';

if (!is_string($conversationId) || !ctype_digit($conversationId)
    || !Conversation::isMember((int) $conversationId, $userId)) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}
$conversationId = (int) $conversationId;

if ($blockId !== '') {
    if (!is_string($blockId) || !ctype_digit($blockId)
        || Message::findBlockConversationId((int) $blockId) !== $conversationId) {
        Response::error(ErrorCode::MESSAGE_BLOCK_NOT_FOUND);
    }
    $blockId = (int) $blockId;
} else {
    $blockId = null;
}

$list = Message::findList($conversationId, $blockId, MESSAGES_LIST_MIN_COUNT);

Response::success([
    'messages'    => $list['messages'],
    'nextBlockId' => $list['nextBlockId'],
    'hasNext'     => $list['nextBlockId'] !== null,
], [
    Action::users(array_column($list['messages'], 'senderId')),
]);
