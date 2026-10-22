<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Conversation.php';
require_once __DIR__ . '/../core/Action.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/ErrorCode.php';

const CONVERSATIONS_DEFAULT_COUNT = 30;
const CONVERSATIONS_MAX_COUNT     = 100;

$userId = Auth::requireUserId();

$offset = $_REQUEST['offset'] ?? '';
$count  = $_REQUEST['count'] ?? '';

if ($offset !== '' && !ctype_digit((string) $offset)) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

if ($count !== '' && (!ctype_digit((string) $count) || (int) $count <= 0)) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

$positionUpdatedAfter = Request::optionalUint('positionUpdatedAfter');
$changedAfter         = Request::optionalUint('changedAfter');

// Фильтры — для разных сценариев синхронизации, вместе не используются;
// у changedAfter курсорная пагинация вместо offset
if ($changedAfter !== null && ($positionUpdatedAfter !== null || $offset !== '')) {
    Response::error(ErrorCode::INVALID_PARAMS);
}

$offset = $offset === '' ? 0 : (int) $offset;
$count  = $count === '' ? CONVERSATIONS_DEFAULT_COUNT : min((int) $count, CONVERSATIONS_MAX_COUNT);

// Список и общий счётчик — из одного снимка транзакции, чтобы версия ответа соответствовала списку
// (при READ COMMITTED у каждого запроса был бы свой снимок — уровень задаём явно)
$db = DB::get();
$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->beginTransaction();
try {
    // Берём на одну больше, чтобы узнать, есть ли следующая страница
    if ($changedAfter !== null) {
        $changed = Conversation::findChangedByMember($userId, $changedAfter, $count + 1);
    } else {
        $conversations = Conversation::findListByMember($userId, $offset, $count + 1, $positionUpdatedAfter);
    }
    $total = Conversation::findTotalUnreadCount($userId);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

// Страница changedAfter — общая для переписок и меток удаления: лишняя запись — самая новая из двух частей
$removedConversations = [];
if ($changedAfter !== null) {
    $conversations        = $changed['conversations'];
    $removedConversations = $changed['removedConversations'];
    $hasNext = count($conversations) + count($removedConversations) > $count;
    if ($hasNext) {
        $lastConversation = end($conversations);
        $lastRemoved      = end($removedConversations);
        if ($lastRemoved && (!$lastConversation || $lastRemoved['changeVersion'] > $lastConversation['changeVersion'])) {
            array_pop($removedConversations);
        } else {
            array_pop($conversations);
        }
    }
} else {
    $hasNext = count($conversations) > $count;
    $conversations = array_slice($conversations, 0, $count);
}

Response::success([
    'conversations'        => $conversations,
    'removedConversations' => $removedConversations,
    'hasNext'              => $hasNext,
    'totalUnreadCount' => $total['totalUnreadCount'],
    'changeVersion'    => $total['changeVersion'],
], [
    Action::users(Conversation::userIdsOf($conversations)),
]);
