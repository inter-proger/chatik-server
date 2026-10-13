<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';

const CONVERSATIONS_BATCH_MAX_COUNT = 100;

$userId = Auth::requireUserId();

$ids = Request::requireIdList('conversationIds', CONVERSATIONS_BATCH_MAX_COUNT);

// Чужие переписки в выдачу не попадают — неотличимы от несуществующих
$found = Conversation::findByIds($ids, $userId);

// Порядок — как в запросе
$conversations = [];
$notFoundIds   = [];
foreach ($ids as $id) {
    if (isset($found[$id])) {
        $conversations[] = $found[$id];
    } else {
        $notFoundIds[] = $id;
    }
}

Response::success([
    'conversations' => $conversations,
    'notFoundIds'   => $notFoundIds,
], [
    Action::users(array_merge([], ...array_column($conversations, 'memberIds'))),
]);
