<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Group.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

$userId = Auth::requireUserId();

$conversationId = Request::parseUint($_REQUEST['conversationId'] ?? '');

// Версия и состав — из одного снимка: иначе состав версии N мог бы прийти с версией N+1,
// и клиент отбросил бы настоящее изменение N+1 как старое
$db = DB::get();
$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->beginTransaction();
try {
    $error     = $conversationId === null ? ErrorCode::CONVERSATION_NOT_FOUND : Group::findAccessError($conversationId, $userId);
    $groupInfo = $error === null ? Group::findInfo($conversationId) : null;
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

if ($error !== null) {
    Response::error($error);
}

Response::success([
    'groupInfo' => $groupInfo,
], [
    Action::users(Group::memberIdsOf($groupInfo)),
]);
