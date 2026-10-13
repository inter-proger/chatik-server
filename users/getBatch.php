<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/User.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';

const USERS_BATCH_MAX_COUNT = 100;

Auth::requireUserId();

$ids = Request::requireIdList('userIds', USERS_BATCH_MAX_COUNT);

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
