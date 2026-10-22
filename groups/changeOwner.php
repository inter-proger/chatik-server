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

// Невалидный id неотличим от пользователя не из группы — проверка в changeOwner() его не найдёт
$newOwnerId = Request::parseUint($_POST['newOwnerUserId'] ?? '') ?? 0;

$groupInfo = Group::changeOwner($conversationId, $userId, $newOwnerId, $error);
if ($groupInfo === null) {
    Response::error($error);
}

Group::publishOwnerChanged($conversationId, $newOwnerId, $groupInfo);

Response::success([
    'groupInfo' => $groupInfo,
]);
