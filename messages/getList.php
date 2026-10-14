<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Message.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

/** Отдаём целые блоки, пока мест (с удалёнными сообщениями) не наберётся хотя бы столько */
const MESSAGES_LIST_MIN_COUNT = 40;

$userId = Auth::requireUserId();

$conversationId = $_REQUEST['conversationId'] ?? '';
$blockId        = $_REQUEST['blockId'] ?? '';

if (!is_string($conversationId) || !ctype_digit($conversationId)
    || !Conversation::isMember((int) $conversationId, $userId)) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}
$conversationId = (int) $conversationId;

// Фильтр догоняющей синхронизации — только для начала переписки, с blockId не используется
$receivedAfter = Request::optionalUint('receivedAfter');
if ($receivedAfter !== null && $blockId !== '') {
    Response::error(ErrorCode::INVALID_PARAMS);
}

if ($blockId !== '') {
    if (!is_string($blockId) || !ctype_digit($blockId)
        || Message::findBlockConversationId((int) $blockId) !== $conversationId) {
        Response::error(ErrorCode::MESSAGE_BLOCK_NOT_FOUND);
    }
    $blockId = (int) $blockId;
} else {
    $blockId = null;
}

if ($receivedAfter !== null) {
    $list = Message::findListReceivedAfter($conversationId, $receivedAfter, MESSAGES_LIST_MIN_COUNT);
} else {
    $list = Message::findList($conversationId, $blockId, MESSAGES_LIST_MIN_COUNT);
    $list['hasNext'] = $list['nextBlockId'] !== null;
}

Response::success($list, [
    Action::users(array_column($list['messages'], 'senderId')),
]);
