<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Group.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$conversationId = Request::parseUint($_POST['conversationId'] ?? '');
if ($conversationId === null) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}

$result = Group::leave($conversationId, $userId, $error);
if ($result === null) {
    Response::error($error);
}

Group::publishMemberLeft($conversationId, $userId, $result);

Response::success([
    'removedConversation' => $result['removedConversation'],
    'totalUnreadCount'    => $result['totalUnreadCount'],
]);
