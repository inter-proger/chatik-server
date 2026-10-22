<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Group.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$conversationId = Request::parseUint($_POST['conversationId'] ?? '');
$userIds        = Request::requireIdList('userIds', Group::MAX_MEMBERS);

if ($conversationId === null) {
    Response::error(ErrorCode::CONVERSATION_NOT_FOUND);
}

// Права и состав проверяются под блокировкой группы
$result = Group::removeMembers($conversationId, $userId, $userIds, $error);
if ($result === null) {
    Response::error($error);
}

Group::publishMembersRemoved($conversationId, $result);

Response::success([
    'removedUserIds'      => $result['removedUserIds'],
    'notRemovedUsersInfo' => $result['notRemovedUsersInfo'],
    'groupInfo'           => $result['groupInfo'],
]);
