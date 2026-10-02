<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Message.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';
require_once __DIR__ . '/../core/WebSocket.php';

$userId = Auth::requireUserId();

$conversationId = $_POST['conversationId'] ?? '';

if (!is_string($conversationId) || !ctype_digit($conversationId)
    || !Conversation::isMember((int) $conversationId, $userId)) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}

$text = Message::normalizeText($_POST['text'] ?? '');
if ($text === null) {
    Response::error(ErrorCode::INVALID_TEXT);
}

$message = Message::send((int) $conversationId, $userId, $text);

WebSocket::publish(
    Conversation::findMemberIds($message['conversationId']), 'messages', 'received',
    ['message' => $message], [$message['senderId']]
);

Response::success([
    'message' => $message,
], [
    Action::users([$message['senderId']]),
]);
