<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Message.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';
require_once __DIR__ . '/../core/WebSocket.php';

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

// read — во все соединения пользователя, включая вызвавшее; memberRead — остальным участникам.
// Одним запросом к WS-серверу; если ничего не изменилось — событий нет
if ($changed) {
    $otherMemberIds = array_values(array_diff(Conversation::findMemberIds((int) $conversationId), [$userId]));
    WebSocket::publishEach([
        WebSocket::delivery([$userId], 'conversations', 'read', ['readInfo' => $readInfo]),
        WebSocket::delivery($otherMemberIds, 'conversations', 'memberRead', [
            'conversationId'    => (int) $conversationId,
            'userId'            => $userId,
            'lastReadMessageId' => $readInfo['lastReadMessageId'],
        ]),
    ]);
}

Response::success([
    'readInfo' => $readInfo,
]);
