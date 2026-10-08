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

$clientId = Message::normalizeClientId($_POST['clientId'] ?? '');
if ($clientId === null) {
    Response::error(ErrorCode::INVALID_CLIENT_ID);
}

$message = Message::send((int) $conversationId, $userId, $text, $clientId, $isNew);

// Повтор с тем же clientId возвращает уже отправленное сообщение — но только из этой же переписки
if ($message['conversationId'] !== (int) $conversationId) {
    Response::error(ErrorCode::MESSAGE_CLIENT_ID_TAKEN);
}

// При повторе событие уже было разослано при первой отправке
if ($isNew) {
    WebSocket::publish(
        Conversation::findMemberIds($message['conversationId']), 'messages', 'received',
        ['message' => $message], [$message['senderId']]
    );
}

Response::success([
    'message' => $message,
], [
    Action::users([$message['senderId']]),
]);
