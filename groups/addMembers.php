<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Group.php';
require_once __DIR__ . '/../core/Action.php';
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
$result = Group::addMembers($conversationId, $userId, $userIds, $error);
if ($result === null) {
    Response::error($error);
}

Group::publishMembersAdded($conversationId, $result);

// Остальных участников клиент владельца уже знает по открытой группе
Response::success([
    'addedUserIds'      => $result['addedUserIds'],
    'notAddedUsersInfo' => $result['notAddedUsersInfo'],
    'groupInfo'         => $result['groupInfo'],
], [
    Action::users($result['addedUserIds']),
]);
