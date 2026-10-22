<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Message.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$conversationId = $_POST['conversationId'] ?? '';
$messageId      = $_POST['messageId'] ?? '';

if (!is_string($conversationId) || !ctype_digit($conversationId)
    || !Conversation::isMember((int) $conversationId, $userId)) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}

// Сообщение из другой переписки неотличимо от несуществующего
$message = is_string($messageId) && ctype_digit($messageId) ? Message::findById((int) $messageId) : null;
if ($message === null || $message['conversationId'] !== (int) $conversationId) {
    Response::error(ErrorCode::MESSAGE_NOT_FOUND);
}

$readInfo = Conversation::markRead((int) $conversationId, $userId, $message['messageId'], $changed);

// Во все соединения пользователя, включая вызвавшее, и остальным участникам; если ничего не изменилось — событий нет
if ($changed) {
    Conversation::publishRead((int) $conversationId, $userId, $readInfo);
}

Response::success([
    'readInfo' => $readInfo,
]);
