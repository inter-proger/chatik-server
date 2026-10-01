<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

const USERS_BATCH_MAX_COUNT = 100;

Auth::requireUserId();

// Принимаем и userIds=1,2,3, и userIds[]=1&userIds[]=2
$userIds = $_REQUEST['userIds'] ?? '';
if (is_string($userIds)) {
    $userIds = $userIds === '' ? [] : explode(',', $userIds);
}

if (!is_array($userIds) || !$userIds || count($userIds) > USERS_BATCH_MAX_COUNT) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

$ids = [];
foreach ($userIds as $id) {
    $id = is_string($id) ? trim($id) : $id;
    if (!is_string($id) || !ctype_digit($id)) {
        Response::error(ErrorCode::INVALID_PARAMS);
    }
    $ids[] = (int) $id;
}
$ids = array_values(array_unique($ids));

$found = User::findByIds($ids);

// Порядок — как в запросе
$users       = [];
$notFoundIds = [];
foreach ($ids as $id) {
    if (isset($found[$id])) {
        $users[] = $found[$id];
    } else {
        $notFoundIds[] = $id;
    }
}

Response::success([
    'users'       => $users,
    'notFoundIds' => $notFoundIds,
]);
