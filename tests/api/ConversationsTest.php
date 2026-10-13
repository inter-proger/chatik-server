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
        $this->assertSame(['conversations' => [], 'hasNext' => false], $res['response']);
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

    public function testGetListUpdatedInfoAfterWithCursor(): void
    {
        [$a, $b, $c, $d] = $this->createConversations(['a', 'b', 'c', 'd']);
        $this->setTime($a, 'updated_info_at', 4000);
        $this->setTime($b, 'updated_info_at', 2000);
        $this->setTime($c, 'updated_info_at', 2000);
        $this->setTime($d, 'updated_info_at', 1000);

        $page = fn(array $params) => $this->client->get('/conversations/getList', $params + ['count' => 2])['response'];

        // По возрастанию updatedInfoAt, при равенстве — по возрастанию conversationId
        $first = $page(['updatedInfoAfter' => 1000]);
        $this->assertSame([$b, $c], array_column($first['conversations'], 'conversationId'));
        $this->assertTrue($first['hasNext']);

        // Изменение во время листания: b уходит в конец выдачи, а не сдвигает страницы
        $this->setTime($b, 'updated_info_at', 5000);

        $last = end($first['conversations']);
        $second = $page(['updatedInfoAfter' => $last['updatedInfoAt'], 'updatedInfoAfterId' => $last['conversationId']]);
        $this->assertSame([$a, $b], array_column($second['conversations'], 'conversationId'));
        $this->assertFalse($second['hasNext']);
    }

    public function testGetListUpdatedInfoAfterTracksLastMessageEdit(): void
    {
        $bob  = $this->insertUser('bob');
        $conversation = $this->client->post('/conversations/createSingle', ['userId' => $bob])['response']['conversation'];
        $message = $this->client->post('/messages/send', [
            'conversationId' => $conversation['conversationId'], 'text' => 'Hi', 'clientId' => 'c1',
        ])['response']['message'];

        $this->assertSame([], $this->client->get('/conversations/getList', ['updatedInfoAfter' => $conversation['updatedInfoAt']])['response']['conversations']);

        $edited = $this->client->post('/messages/edit', ['messageId' => $message['messageId'], 'newText' => 'Hi!'])['response']['message'];

        $list = $this->client->get('/conversations/getList', ['updatedInfoAfter' => $conversation['updatedInfoAt']])['response']['conversations'];
        $this->assertSame([$conversation['conversationId']], array_column($list, 'conversationId'));
        $this->assertSame($edited['updatedAt'], $list[0]['updatedInfoAt']);
        $this->assertSame('Hi!', $list[0]['lastMessage']['text']);
    }

    public function testGetListFilterParamsInvalid(): void
    {
        $invalid = [
            ['positionUpdatedAfter' => '1', 'updatedInfoAfter' => '1'],
            ['updatedInfoAfter' => '1', 'offset' => '0'],
            ['updatedInfoAfterId' => '1'],
            ['positionUpdatedAfter' => '-1'],
            ['positionUpdatedAfter' => '1.5'],
            ['updatedInfoAfter' => 'abc'],
            ['updatedInfoAfter' => '1', 'updatedInfoAfterId' => 'x'],
            ['positionUpdatedAfter' => ['1']],
        ];
        foreach ($invalid as $params) {
            $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->get('/conversations/getList', $params));
        }

        // positionUpdatedAfter совместим с offset
        $this->assertSuccess($this->client->get('/conversations/getList', ['positionUpdatedAfter' => '0', 'offset' => '0']));
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
