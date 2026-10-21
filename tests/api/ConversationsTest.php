<?php

require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

class ConversationsTest extends TestCase
{
    private ApiClient $client;
    private int $me;

    public function setUp(): void
    {
        parent::setUp();
        [$this->client, $user] = $this->signup('me', 'Me');
        $this->me = $user['userId'];
    }

    // ---- /conversations/createSingle ----

    public function testCreateSingle(): void
    {
        $bob = $this->insertUser('bob', 'Bob');

        $res = $this->client->post('/conversations/createSingle', ['userId' => $bob]);

        $this->assertSuccess($res);
        $createdAt = (int) DB::get()->query('SELECT ' . DB::ms('created_at') . ' FROM conversations')->fetchColumn();
        $this->assertSame([
            'conversationId'    => 1,
            'type'              => 'single',
            'memberIds'         => [$this->me, $bob],
            'name'              => null,
            'avatar'            => null,
            'opponentUserId'    => $bob,
            'lastMessage'       => null,
            'positionUpdatedAt' => $createdAt,
            'updatedInfoAt'     => $createdAt,
            'unreadCount'       => 0,
            'lastReadMessageId' => 0,
            'changeVersion'     => 1,
        ], $res['response']['conversation']);
        $this->assertUsersAction([$this->me, $bob], $res);
    }

    public function testCreateSingleIsIdempotentForBothSides(): void
    {
        [$bobClient, $bob] = $this->signup('bob');

        $mine   = $this->client->post('/conversations/createSingle', ['userId' => $bob['userId']])['response']['conversation'];
        $again  = $this->client->post('/conversations/createSingle', ['userId' => $bob['userId']])['response']['conversation'];
        $theirs = $bobClient->post('/conversations/createSingle', ['userId' => $this->me])['response']['conversation'];

        $this->assertSame($mine, $again);
        $this->assertSame($mine['conversationId'], $theirs['conversationId']);
        $this->assertSame($this->me, $theirs['opponentUserId']);
    }

    public function testCreateSingleWithSelf(): void
    {
        $res = $this->client->post('/conversations/createSingle', ['userId' => $this->me]);

        $this->assertError(ErrorCode::CONVERSATION_WITH_SELF, $res);
    }

    public function testCreateSingleUserNotFound(): void
    {
        foreach (['', '0', 'abc', '-2', '999'] as $userId) {
            $res = $this->client->post('/conversations/createSingle', ['userId' => $userId]);
            $this->assertError(ErrorCode::USER_NOT_FOUND, $res);
        }
    }

    public function testCreateSingleNotAuthenticated(): void
    {
        $res = $this->client()->post('/conversations/createSingle', ['userId' => $this->me]);

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $res);
    }

    // ---- /conversations/getList ----

    public function testGetListEmpty(): void
    {
        $res = $this->client->get('/conversations/getList');

        $this->assertSuccess($res);
        $this->assertSame(['conversations' => [], 'hasNext' => false, 'totalUnreadCount' => 0, 'changeVersion' => 0], $res['response']);
        $this->assertFalse(array_key_exists('actions', $res));
    }

    public function testGetListNewestFirstOnlyOwn(): void
    {
        $bob   = $this->insertUser('bob');
        $carol = $this->insertUser('carol');
        [$daveClient] = $this->signup('dave');
        $daveClient->post('/conversations/createSingle', ['userId' => $bob]);

        $this->client->post('/conversations/createSingle', ['userId' => $bob]);
        $this->client->post('/conversations/createSingle', ['userId' => $carol]);

        $res = $this->client->get('/conversations/getList');

        $this->assertSame([$carol, $bob], array_column($res['response']['conversations'], 'opponentUserId'));
        $this->assertFalse($res['response']['hasNext']);
        // dave в выдачу не попал — его переписки с bob в выдаче нет
        $this->assertUsersAction([$this->me, $bob, $carol], $res);
    }

    public function testGetListPagination(): void
    {
        $ids = [];
        foreach (['a', 'b', 'c'] as $login) {
            $ids[$login] = $this->insertUser($login);
            $this->client->post('/conversations/createSingle', ['userId' => $ids[$login]]);
        }

        $opponents = fn($res) => array_column($res['response']['conversations'], 'opponentUserId');

        $page1 = $this->client->post('/conversations/getList', ['count' => 2]);
        $this->assertSame([$ids['c'], $ids['b']], $opponents($page1));
        $this->assertTrue($page1['response']['hasNext']);

        $page2 = $this->client->post('/conversations/getList', ['count' => 2, 'offset' => 2]);
        $this->assertSame([$ids['a']], $opponents($page2));
        $this->assertFalse($page2['response']['hasNext']);

        $this->assertSame([], $this->client->post('/conversations/getList', ['offset' => 10])['response']['conversations']);
    }

    public function testGetListRaisedByNewMessage(): void
    {
        $bob   = $this->insertUser('bob');
        $carol = $this->insertUser('carol');
        $withBob   = $this->client->post('/conversations/createSingle', ['userId' => $bob])['response']['conversation'];
        $withCarol = $this->client->post('/conversations/createSingle', ['userId' => $carol])['response']['conversation'];

        $message = $this->client->post('/messages/send', [
            'conversationId' => $withBob['conversationId'], 'text' => 'Hi', 'clientId' => 'c1',
        ])['response']['message'];

        $list = $this->client->get('/conversations/getList')['response']['conversations'];
        $this->assertSame([$withBob['conversationId'], $withCarol['conversationId']], array_column($list, 'conversationId'));
        $this->assertSame(Message::toLastMessage($message), $list[0]['lastMessage']);
        $this->assertSame($message['createdAt'], $list[0]['positionUpdatedAt']);
        $this->assertSame($withBob['updatedInfoAt'], $list[0]['updatedInfoAt']);
        $this->assertNull($list[1]['lastMessage']);
    }

    public function testGetListPositionUpdatedAfter(): void
    {
        [$a, $b, $c] = $this->createConversations(['a', 'b', 'c']);
        $this->setTime($a, 'position_updated_at', 3000);
        $this->setTime($b, 'position_updated_at', 2000);
        $this->setTime($c, 'position_updated_at', 1000);

        $ids = fn($res) => array_column($res['response']['conversations'], 'conversationId');

        $res = $this->client->get('/conversations/getList', ['positionUpdatedAfter' => 1000]);
        $this->assertSame([$a, $b], $ids($res));
        $this->assertFalse($res['response']['hasNext']);

        // Поднялось больше страницы — hasNext в пределах фильтра
        $res = $this->client->get('/conversations/getList', ['positionUpdatedAfter' => 1000, 'count' => 1]);
        $this->assertSame([$a], $ids($res));
        $this->assertTrue($res['response']['hasNext']);

        $res = $this->client->get('/conversations/getList', ['positionUpdatedAfter' => 1000, 'count' => 1, 'offset' => 1]);
        $this->assertSame([$b], $ids($res));
        $this->assertFalse($res['response']['hasNext']);

        $this->assertSame([], $ids($this->client->get('/conversations/getList', ['positionUpdatedAfter' => 3000])));
    }

    public function testGetListReturnsTotalUnreadCount(): void
    {
        [$bobClient, $bob] = $this->signup('bob');
        $id = $this->client->post('/conversations/createSingle', ['userId' => $bob['userId']])['response']['conversation']['conversationId'];
        $bobClient->post('/messages/send', ['conversationId' => $id, 'text' => 'Hi', 'clientId' => 'c1']);

        $res = $this->client->get('/conversations/getList', ['count' => 1])['response'];

        $this->assertSame(1, $res['totalUnreadCount']);
        $this->assertSame(2, $res['changeVersion']);
        $this->assertSame([1, 0, 2], [$res['conversations'][0]['unreadCount'], $res['conversations'][0]['lastReadMessageId'], $res['conversations'][0]['changeVersion']]);
    }

    public function testGetListChangedAfterWithCursor(): void
    {
        [$a, $b, $c, $d] = $this->createConversations(['a', 'b', 'c', 'd']);

        $page = fn(array $params) => $this->client->get('/conversations/getList', $params + ['count' => 2])['response'];
        $ids  = fn(array $response) => array_column($response['conversations'], 'conversationId');

        // Создание — версии 1..4; по возрастанию версии
        $first = $page(['changedAfter' => 1]);
        $this->assertSame([$b, $c], $ids($first));
        $this->assertTrue($first['hasNext']);
        $this->assertSame(4, $first['changeVersion']);

        // Изменение во время листания: b уходит в конец выдачи, а не сдвигает страницы
        $this->client->post('/messages/send', ['conversationId' => $b, 'text' => 'Hi', 'clientId' => 'c1']);

        $second = $page(['changedAfter' => end($first['conversations'])['changeVersion']]);
        $this->assertSame([$d, $b], $ids($second));
        $this->assertSame([4, 5], array_column($second['conversations'], 'changeVersion'));
        $this->assertFalse($second['hasNext']);
        $this->assertSame(5, $second['changeVersion']);

        $this->assertSame([], $ids($page(['changedAfter' => 5])));
    }

    public function testGetListChangedAfterTracksReadAndLastMessageEdit(): void
    {
        [$bobClient, $bob] = $this->signup('bob');
        $conversation = $this->client->post('/conversations/createSingle', ['userId' => $bob['userId']])['response']['conversation'];
        $id = $conversation['conversationId'];
        $message = $bobClient->post('/messages/send', ['conversationId' => $id, 'text' => 'Hi', 'clientId' => 'c1'])['response']['message'];
        $synced = $this->client->get('/conversations/getList')['response']['changeVersion'];

        // Прочтение на другом устройстве не меняет ни позицию, ни updatedInfoAt, но попадает в выдачу
        $this->client->post('/conversations/markRead', ['conversationId' => $id, 'messageId' => $message['messageId']]);

        $res = $this->client->get('/conversations/getList', ['changedAfter' => $synced])['response'];
        $this->assertSame([$id], array_column($res['conversations'], 'conversationId'));
        $this->assertSame([0, $message['messageId']], [$res['conversations'][0]['unreadCount'], $res['conversations'][0]['lastReadMessageId']]);
        $this->assertSame(0, $res['totalUnreadCount']);
        $synced = $res['changeVersion'];

        // Правка последнего сообщения собеседником
        $edited = $bobClient->post('/messages/edit', ['messageId' => $message['messageId'], 'newText' => 'Hi!'])['response']['message'];

        $list = $this->client->get('/conversations/getList', ['changedAfter' => $synced])['response']['conversations'];
        $this->assertSame([$id], array_column($list, 'conversationId'));
        $this->assertSame($edited['updatedAt'], $list[0]['updatedInfoAt']);
        $this->assertSame('Hi!', $list[0]['lastMessage']['text']);
    }

    public function testGetListFilterParamsInvalid(): void
    {
        $invalid = [
            ['positionUpdatedAfter' => '1', 'changedAfter' => '1'],
            ['changedAfter' => '1', 'offset' => '0'],
            ['positionUpdatedAfter' => '-1'],
            ['positionUpdatedAfter' => '1.5'],
            ['changedAfter' => 'abc'],
            ['changedAfter' => '-1'],
            ['positionUpdatedAfter' => ['1']],
        ];
        foreach ($invalid as $params) {
            $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->get('/conversations/getList', $params));
        }

        // positionUpdatedAfter совместим с offset, changedAfter — с count
        $this->assertSuccess($this->client->get('/conversations/getList', ['positionUpdatedAfter' => '0', 'offset' => '0']));
        $this->assertSuccess($this->client->get('/conversations/getList', ['changedAfter' => '0', 'count' => '5']));
    }

    public function testGetListCountIsCappedAt100(): void
    {
        $db   = DB::get();
        $stmt = $db->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
        for ($i = 0; $i < 101; $i++) {
            $db->exec("INSERT INTO conversations (type, name) VALUES ('group', 'g$i')");
            $stmt->execute([(int) $db->lastInsertId(), $this->me]);
        }

        $res = $this->client->get('/conversations/getList', ['count' => 1000]);

        $this->assertCount(100, $res['response']['conversations']);
        $this->assertTrue($res['response']['hasNext']);
        $this->assertSame('g100', $res['response']['conversations'][0]['name']);
    }

    public function testGetListInvalidParams(): void
    {
        foreach ([['offset' => '-1'], ['offset' => 'x'], ['count' => '0'], ['count' => '1.5']] as $params) {
            $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->get('/conversations/getList', $params));
        }
    }

    public function testGetListNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->get('/conversations/getList'));
    }

    // ---- /conversations/markRead ----

    public function testMarkRead(): void
    {
        [$bobClient, $bob] = $this->signup('bob');
        $id = $this->client->post('/conversations/createSingle', ['userId' => $bob['userId']])['response']['conversation']['conversationId'];
        $ids = array_map(
            fn($i) => $bobClient->post('/messages/send', ['conversationId' => $id, 'text' => "m$i", 'clientId' => "c$i"])['response']['message']['messageId'],
            [1, 2, 3]
        );

        $res = $this->client->post('/conversations/markRead', ['conversationId' => $id, 'messageId' => $ids[1]]);

        $this->assertSuccess($res);
        $this->assertSame(['readInfo' => [
            'conversationId' => $id, 'lastReadMessageId' => $ids[1], 'unreadCount' => 1, 'totalUnreadCount' => 1, 'changeVersion' => 5,
        ]], $res['response']);
        $this->assertFalse(array_key_exists('actions', $res));
        $this->assertSame(1, Conversation::findById($id, $this->me)['unreadCount']);
        $this->assertUnreadInvariant();
    }

    public function testMarkReadOlderOrRepeatChangesNothing(): void
    {
        [$bobClient, $bob] = $this->signup('bob');
        $id = $this->client->post('/conversations/createSingle', ['userId' => $bob['userId']])['response']['conversation']['conversationId'];
        $first  = $bobClient->post('/messages/send', ['conversationId' => $id, 'text' => 'one', 'clientId' => 'c1'])['response']['message'];
        $second = $bobClient->post('/messages/send', ['conversationId' => $id, 'text' => 'two', 'clientId' => 'c2'])['response']['message'];
        $readInfo = $this->client->post('/conversations/markRead', ['conversationId' => $id, 'messageId' => $second['messageId']])['response']['readInfo'];

        foreach ([$second, $first] as $message) {
            $res = $this->client->post('/conversations/markRead', ['conversationId' => $id, 'messageId' => $message['messageId']]);
            $this->assertSame(['readInfo' => $readInfo], $res['response']);
        }
    }

    public function testMarkReadConversationNotFound(): void
    {
        [$daveClient] = $this->signup('dave');
        $carol = $this->insertUser('carol');
        $foreign = $daveClient->post('/conversations/createSingle', ['userId' => $carol])['response']['conversation']['conversationId'];
        $messageId = $daveClient->post('/messages/send', ['conversationId' => $foreign, 'text' => 'Hi', 'clientId' => 'c1'])['response']['message']['messageId'];

        foreach (['', 'abc', '-1', '999', (string) $foreign] as $conversationId) {
            $res = $this->client->post('/conversations/markRead', ['conversationId' => $conversationId, 'messageId' => $messageId]);
            $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $res);
        }
    }

    public function testMarkReadMessageNotFound(): void
    {
        [$daveClient, $dave] = $this->signup('dave');
        $mine = $this->client->post('/conversations/createSingle', ['userId' => $dave['userId']])['response']['conversation']['conversationId'];
        $foreign = $daveClient->post('/conversations/createSingle', ['userId' => $this->insertUser('carol')])['response']['conversation']['conversationId'];
        $foreignMessageId = $daveClient->post('/messages/send', ['conversationId' => $foreign, 'text' => 'Hi', 'clientId' => 'c1'])['response']['message']['messageId'];

        // Сообщение из другой переписки (даже доступной) неотличимо от несуществующего
        foreach (['', 'abc', '-1', '999', (string) $foreignMessageId] as $messageId) {
            $res = $this->client->post('/conversations/markRead', ['conversationId' => $mine, 'messageId' => $messageId]);
            $this->assertError(ErrorCode::MESSAGE_NOT_FOUND, $res);
        }
    }

    public function testMarkReadNotAuthenticated(): void
    {
        $res = $this->client()->post('/conversations/markRead', ['conversationId' => 1, 'messageId' => 1]);

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $res);
    }

    // ---- /conversations/getBatch ----

    public function testGetBatch(): void
    {
        [$a, $b] = $this->createConversations(['a', 'b']);
        [$daveClient] = $this->signup('dave');
        $carol = $this->insertUser('carol');
        $foreign = $daveClient->post('/conversations/createSingle', ['userId' => $carol])['response']['conversation']['conversationId'];

        $res = $this->client->get('/conversations/getBatch', ['conversationIds' => "$b,999,$a,$foreign,$b"]);

        $this->assertSuccess($res);
        $this->assertSame([
            'conversations' => [Conversation::findById($b, $this->me), Conversation::findById($a, $this->me)],
            'notFoundIds'   => [999, $foreign],
        ], $res['response']);
        $this->assertUsersAction(array_values(array_unique(array_merge(...array_column($res['response']['conversations'], 'memberIds')))), $res);
    }

    public function testGetBatchArrayParam(): void
    {
        [$a] = $this->createConversations(['a']);

        $res = $this->client->get('/conversations/getBatch', ['conversationIds' => [(string) $a]]);

        $this->assertSame([$a], array_column($res['response']['conversations'], 'conversationId'));
    }

    public function testGetBatchNothingFound(): void
    {
        $res = $this->client->get('/conversations/getBatch', ['conversationIds' => '5']);

        $this->assertSame(['conversations' => [], 'notFoundIds' => [5]], $res['response']);
        $this->assertFalse(array_key_exists('actions', $res));
    }

    public function testGetBatchInvalidParams(): void
    {
        $tooMany = implode(',', range(1, 101));
        foreach ([[], ['conversationIds' => ''], ['conversationIds' => '1,x'], ['conversationIds' => '-1'], ['conversationIds' => $tooMany]] as $params) {
            $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->get('/conversations/getBatch', $params));
        }
        $this->assertSuccess($this->client->get('/conversations/getBatch', ['conversationIds' => implode(',', range(1, 100))]));
    }

    public function testGetBatchNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->get('/conversations/getBatch', ['conversationIds' => '1']));
    }

    /** Личные переписки текущего пользователя с новыми пользователями $logins — их id в том же порядке. */
    private function createConversations(array $logins): array
    {
        return array_map(
            fn($login) => $this->client->post('/conversations/createSingle', ['userId' => $this->insertUser($login)])['response']['conversation']['conversationId'],
            $logins
        );
    }

    /** Ставит колонке времени $column переписки значение в миллисекундах. */
    private function setTime(int $conversationId, string $column, int $ms): void
    {
        DB::get()->prepare("UPDATE conversations SET $column = " . DB::fromMs() . ' WHERE id = ?')->execute([$ms, $conversationId]);
    }
}
