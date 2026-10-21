<?php

require_once __DIR__ . '/../core/Admin.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Message.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';
require_once __DIR__ . '/../core/WebSocket.php';

Admin::require();

$userId         = $_POST['userId'] ?? '';
$conversationId = $_POST['conversationId'] ?? '';

if (!is_string($userId) || !ctype_digit($userId) || User::findById((int) $userId) === null) {
    Response::error(ErrorCode::USER_NOT_FOUND);
}

if (!is_string($conversationId) || !ctype_digit($conversationId)
    || !Conversation::isMember((int) $conversationId, (int) $userId)) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}

$text = Message::normalizeText($_POST['text'] ?? '');
if ($text === null) {
    Response::error(ErrorCode::INVALID_TEXT);
}

$message = Message::send((int) $conversationId, (int) $userId, $text, null, $isNew, $readInfos);

// Как в /messages/send
Message::publishReceived($message, $readInfos);

Response::success([
    'message' => $message,
]);
