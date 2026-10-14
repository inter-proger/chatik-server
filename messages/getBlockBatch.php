<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Message.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';

$userId = Auth::requireUserId();

$ids = Request::requireIdList('blockIds', Message::BLOCK_BATCH_MAX_COUNT);

// Блоки чужих переписок в выдачу не попадают — неотличимы от несуществующих
$found = Message::findBlocksByIds($ids, $userId);

// Порядок — как в запросе
$blocks      = [];
$notFoundIds = [];
foreach ($ids as $id) {
    if (isset($found[$id])) {
        $blocks[] = $found[$id];
    } else {
        $notFoundIds[] = $id;
    }
}

Response::success([
    'blocks'      => $blocks,
    'notFoundIds' => $notFoundIds,
], [
    Action::users(array_column(array_merge([], ...array_column($blocks, 'messages')), 'senderId')),
]);
