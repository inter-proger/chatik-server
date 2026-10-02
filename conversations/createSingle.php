<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';
require_once __DIR__ . '/../core/WebSocket.php';

$userId = Auth::requireUserId();

$otherUserId = $_POST['userId'] ?? '';

if (!ctype_digit((string) $otherUserId) || (int) $otherUserId <= 0) {
    Response::error(ErrorCode::USER_NOT_FOUND);
}
$otherUserId = (int) $otherUserId;

if ($otherUserId === $userId) {
    Response::error(ErrorCode::CONVERSATION_WITH_SELF);
}

if (User::findById($otherUserId) === null) {
    Response::error(ErrorCode::USER_NOT_FOUND);
}

$conversation = Conversation::findOrCreateSingle($userId, $otherUserId, $created);

if ($created) {
    // opponentUserId зависит от получателя — каждому участнику своя доставка
    WebSocket::publishEach(array_map(
        fn($memberId) => WebSocket::delivery(
            [$memberId], 'conversations', 'singleCreated',
            ['conversation' => Conversation::findById($conversation['conversationId'], $memberId)],
            $conversation['memberIds']
        ),
        $conversation['memberIds']
    ));
}

Response::success([
    'conversation' => $conversation,
], [
    Action::users($conversation['memberIds']),
]);
