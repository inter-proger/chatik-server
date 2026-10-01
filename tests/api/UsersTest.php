<?php

class UsersTest extends TestCase
{
    private ApiClient $client;

    public function setUp(): void
    {
        parent::setUp();
        [$this->client] = $this->signup('me', 'Me');
    }

    private function logins(array $res): array
    {
        return array_column($res['response']['users'], 'login');
    }

    public function testDefaultList(): void
    {
        $this->insertUser('bob', 'Bob');

        $res = $this->client->get('/users/getList');

        $this->assertSuccess($res);
        $this->assertSame(['me', 'bob'], $this->logins($res));
        $this->assertSame(2, $res['response']['nextMaxId']);
        $this->assertFalse($res['response']['hasNext']);
        $this->assertSame(['userId', 'login', 'name', 'avatar'], array_keys($res['response']['users'][0]));
    }

    public function testDefaultCountIs30(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->insertUser("u$i");
        }

        $res = $this->client->get('/users/getList');

        $this->assertCount(30, $res['response']['users']);
        $this->assertTrue($res['response']['hasNext']);
    }

    public function testCountIsCappedAt100(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->insertUser("u$i");
        }

        $res = $this->client->get('/users/getList', ['count' => 500]);

        $this->assertCount(100, $res['response']['users']);
        $this->assertTrue($res['response']['hasNext']);
    }

    public function testPagination(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $login) {
            $this->insertUser($login);
        }

        $page1 = $this->client->post('/users/getList', ['count' => 2]);
        $this->assertSame(['me', 'a'], $this->logins($page1));
        $this->assertTrue($page1['response']['hasNext']);

        $page2 = $this->client->post('/users/getList', ['count' => 2, 'maxId' => $page1['response']['nextMaxId']]);
        $this->assertSame(['b', 'c'], $this->logins($page2));
        $this->assertTrue($page2['response']['hasNext']);

        $page3 = $this->client->post('/users/getList', ['count' => 2, 'maxId' => $page2['response']['nextMaxId']]);
        $this->assertSame(['d'], $this->logins($page3));
        $this->assertFalse($page3['response']['hasNext']);

        $page4 = $this->client->post('/users/getList', ['count' => 2, 'maxId' => $page3['response']['nextMaxId']]);
        $this->assertSame([], $page4['response']['users']);
        $this->assertNull($page4['response']['nextMaxId']);
        $this->assertFalse($page4['response']['hasNext']);
    }

    public function testQuery(): void
    {
        $this->insertUser('ivan', 'Иван');
        $this->insertUser('ivanov', 'Pyotr');
        $this->insertUser('olga', 'Ольга Иванова');

        $this->assertSame(['ivan', 'ivanov'], $this->logins($this->client->get('/users/getList', ['query' => 'IVAN'])));
        $this->assertSame(['ivan', 'olga'], $this->logins($this->client->get('/users/getList', ['query' => ' иван '])));
        $this->assertSame([], $this->logins($this->client->get('/users/getList', ['query' => '%'])));
    }

    public function testQueryWithInvalidUtf8(): void
    {
        $res = $this->client->get('/users/getList', ['query' => "me\xFF"]);

        $this->assertSuccess($res);
        $this->assertSame([], $res['response']['users']);
    }

    public function testInvalidParams(): void
    {
        $invalid = [
            ['maxId' => 'abc'], ['maxId' => '-1'], ['count' => '0'], ['count' => 'ten'], ['count' => '-3'],
            ['query' => ['a']], ['maxId' => ['1']], ['count' => ['1']],
        ];
        foreach ($invalid as $params) {
            $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->get('/users/getList', $params));
        }
    }

    public function testNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->get('/users/getList'));
    }

    // ---- /users/getBatch ----

    public function testGetBatch(): void
    {
        $bob   = $this->insertUser('bob', 'Bob');
        $carol = $this->insertUser('carol', 'Carol');

        $res = $this->client->post('/users/getBatch', ['userIds' => "$carol,999,$bob,$carol"]);

        $this->assertSuccess($res);
        $this->assertSame(['carol', 'bob'], $this->logins($res));
        $this->assertSame(['userId' => $bob, 'login' => 'bob', 'name' => 'Bob', 'avatar' => null], $res['response']['users'][1]);
        $this->assertSame([999], $res['response']['errorIdList']);
        $this->assertFalse(array_key_exists('actions', $res));
    }

    public function testGetBatchArrayParam(): void
    {
        $bob = $this->insertUser('bob');

        $res = $this->client->get('/users/getBatch', ['userIds' => [(string) $bob, '0']]);

        $this->assertSuccess($res);
        $this->assertSame(['bob'], $this->logins($res));
        $this->assertSame([0], $res['response']['errorIdList']);
    }

    public function testGetBatchNoneFound(): void
    {
        $res = $this->client->get('/users/getBatch', ['userIds' => '998, 999']);

        $this->assertSuccess($res);
        $this->assertSame(['users' => [], 'errorIdList' => [998, 999]], $res['response']);
    }

    public function testGetBatchMax100(): void
    {
        $ids = range(1, 100);
        $this->assertSuccess($this->client->post('/users/getBatch', ['userIds' => implode(',', $ids)]));

        $ids[] = 101;
        $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->post('/users/getBatch', ['userIds' => implode(',', $ids)]));
        $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->post('/users/getBatch', ['userIds' => $ids]));
    }

    public function testGetBatchInvalidParams(): void
    {
        $invalid = [[], ['userIds' => ''], ['userIds' => 'abc'], ['userIds' => '1,,2'], ['userIds' => '1,-2'], ['userIds' => [['1']]]];
        foreach ($invalid as $params) {
            $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->get('/users/getBatch', $params));
        }
    }

    public function testGetBatchNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->get('/users/getBatch', ['userIds' => '1']));
    }
}
