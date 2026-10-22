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

$message = Message::send((int) $conversationId, $userId, $text, $clientId, $isNew, $readInfos);

// Удалён из группы после проверки выше — участие перепроверено под блокировкой
if ($message === null) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}

// Повтор с тем же clientId возвращает уже отправленное сообщение — но только из этой же переписки
if ($message['conversationId'] !== (int) $conversationId) {
    Response::error(ErrorCode::MESSAGE_CLIENT_ID_TAKEN);
}

// При повторе событие уже было разослано при первой отправке; readInfo — текущий, версия не растёт
if ($isNew) {
    Message::publishReceived($message, $readInfos);
} else {
    $readInfos = Conversation::findReadInfos($message['conversationId'], [$userId]);
}

Response::success([
    'message'  => $message,
    'readInfo' => $readInfos[$userId],
], [
    Action::users([$message['senderId']]),
]);
