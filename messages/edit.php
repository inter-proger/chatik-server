<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Message.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';
require_once __DIR__ . '/../core/WebSocket.php';

$userId = Auth::requireUserId();

$messageId = $_POST['messageId'] ?? '';

if (!is_string($messageId) || !ctype_digit($messageId)) {
    Response::error(ErrorCode::MESSAGE_NOT_FOUND);
}

// Сообщение из чужой переписки неотличимо от несуществующего
$message = Message::findById((int) $messageId);
if ($message === null || !Conversation::isMember($message['conversationId'], $userId)) {
    Response::error(ErrorCode::MESSAGE_NOT_FOUND);
}

if ($message['senderId'] !== $userId) {
    Response::error(ErrorCode::MESSAGE_EDIT_FORBIDDEN);
}

$text = Message::normalizeText($_POST['newText'] ?? '');
if ($text === null) {
    Response::error(ErrorCode::INVALID_TEXT);
}

// Текст не изменился — сообщение не трогаем и отредактированным не помечаем
$body = ['message' => $message];
if ($text !== $message['text']) {
    $message = Message::edit($message['messageId'], $text, $isLast, $changeVersions);

    // Удалён из группы после проверки выше — участие перепроверено под блокировкой
    if ($message === null) {
        Response::error(ErrorCode::MESSAGE_NOT_FOUND);
    }

    // lastMessage — только если отредактировано последнее сообщение переписки
    $body = ['message' => $message] + ($isLast ? ['lastMessage' => Message::toLastMessage($message)] : []);

    // Правка последнего сообщения поднимает версию участников — каждому своя доставка с его версией
    $memberIds = $isLast ? array_keys($changeVersions) : Conversation::findMemberIds($message['conversationId']);
    WebSocket::publishEach(array_map(
        fn($memberId) => WebSocket::delivery(
            [$memberId], 'messages', 'edited',
            $body + ($isLast ? ['changeVersion' => $changeVersions[$memberId]] : []), [$message['senderId']]
        ),
        $memberIds
    ));
}

Response::success($body, [
    Action::users([$message['senderId']]),
]);
