<?php

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
        $this->assertSame([
            'conversationId' => 1,
            'type'           => 'single',
            'memberIds'      => [$this->me, $bob],
            'name'           => null,
            'avatar'         => null,
            'opponentUser'   => ['userId' => $bob, 'login' => 'bob', 'name' => 'Bob', 'avatar' => null],
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
        $this->assertSame('me', $theirs['opponentUser']['login']);
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

        $this->assertSame(['carol', 'bob'], array_map(
            fn($c) => $c['opponentUser']['login'],
            $res['response']['conversations']
        ));
        $this->assertFalse($res['response']['hasNext']);
        // dave в выдачу не попал — его переписки с bob в выдаче нет
        $this->assertUsersAction([$this->me, $bob, $carol], $res);
    }

    public function testGetListPagination(): void
    {
        foreach (['a', 'b', 'c'] as $login) {
            $this->client->post('/conversations/createSingle', ['userId' => $this->insertUser($login)]);
        }

        $opponents = fn($res) => array_map(fn($c) => $c['opponentUser']['login'], $res['response']['conversations']);

        $page1 = $this->client->post('/conversations/getList', ['count' => 2]);
        $this->assertSame(['c', 'b'], $opponents($page1));
        $this->assertTrue($page1['response']['hasNext']);

        $page2 = $this->client->post('/conversations/getList', ['count' => 2, 'offset' => 2]);
        $this->assertSame(['a'], $opponents($page2));
        $this->assertFalse($page2['response']['hasNext']);

        $this->assertSame([], $this->client->post('/conversations/getList', ['offset' => 10])['response']['conversations']);
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
}
