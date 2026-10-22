<?php

require_once __DIR__ . '/../core/Admin.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

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

$userId         = (int) $userId;
$conversationId = (int) $conversationId;

// Читаем до последнего сообщения; в переписке без сообщений читать нечего — событий нет
$lastMessage = Conversation::findById($conversationId, $userId)['lastMessage'];
if ($lastMessage === null) {
    $readInfo = Conversation::findReadInfos($conversationId, [$userId])[$userId];
} else {
    $readInfo = Conversation::markRead($conversationId, $userId, $lastMessage['messageId'], $changed);
    if ($readInfo === null) {
        Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
    }

    // Как в /conversations/markRead
    if ($changed) {
        Conversation::publishRead($conversationId, $userId, $readInfo);
    }
}

Response::success([
    'readInfo' => $readInfo,
]);
