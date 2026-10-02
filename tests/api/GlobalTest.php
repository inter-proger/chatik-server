<?php

class GlobalTest extends TestCase
{
    // ---- /global/start ----

    public function testStartAnonymous(): void
    {
        $client = $this->client();

        $res = $client->get('/global/start');

        $this->assertSuccess($res);
        $this->assertSame(['websocketConnectInfo' => null], $res['response']);
        $this->assertSame(['no-store'], $client->lastHeaders['cache-control'] ?? null);
        // Аноним без cookie не получает пустую сессию
        $this->assertSame([], $client->cookies);
    }

    public function testStartAuthorized(): void
    {
        [$client, $user] = $this->signup('me');

        $res = $client->get('/global/start');

        $this->assertSuccess($res);
        $this->assertConnectInfo($res['response']['websocketConnectInfo'], $user['userId'], $client);
        $this->assertSame(['no-store'], $client->lastHeaders['cache-control'] ?? null);
    }

    public function testStartAfterLogout(): void
    {
        [$client] = $this->signup('me');
        $client->post('/auth/logout');

        $this->assertSame(['websocketConnectInfo' => null], $client->get('/global/start')['response']);
    }

    public function testStartForDeletedUser(): void
    {
        [$client, $user] = $this->signup('me');
        DB::get()->exec('DELETE FROM users WHERE id = ' . $user['userId']);

        $this->assertSame(['websocketConnectInfo' => null], $client->get('/global/start')['response']);
    }

    public function testStartIssuesNewTokenEachCall(): void
    {
        [$client] = $this->signup('me');

        $first  = $client->get('/global/start')['response']['websocketConnectInfo']['token'];
        $second = $client->get('/global/start')['response']['websocketConnectInfo']['token'];

        $this->assertFalse($first === $second);
    }

    // ---- /global/getWebsocketToken ----

    public function testGetWebsocketToken(): void
    {
        [$client, $user] = $this->signup('me');

        $res = $client->get('/global/getWebsocketToken');

        $this->assertSuccess($res);
        $this->assertSame(['websocketConnectInfo'], array_keys($res['response']));
        $this->assertConnectInfo($res['response']['websocketConnectInfo'], $user['userId'], $client);
        $this->assertSame(['no-store'], $client->lastHeaders['cache-control'] ?? null);
    }

    public function testGetWebsocketTokenNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->get('/global/getWebsocketToken'));
    }

    // ---- Отзыв токенов ----

    public function testLogoutRevokesTokens(): void
    {
        [$client] = $this->signup('me');
        $this->wsToken($client);
        [$otherClient] = $this->signup('other');
        $this->wsToken($otherClient);

        $client->post('/auth/logout');

        $this->assertSame(1, $this->tokenCount());
    }

    public function testLoginRevokesTokensOfOldSession(): void
    {
        [$client] = $this->signup('me');
        $this->wsToken($client);
        $this->insertUser('bob');

        $this->assertSuccess($client->post('/auth/login', ['login' => 'bob', 'password' => 'Passw0rd']));

        $this->assertSame(0, $this->tokenCount());
    }

    public function testSignupRevokesTokensOfOldSession(): void
    {
        [$client] = $this->signup('me');
        $this->wsToken($client);

        $this->assertSuccess($client->post('/auth/signup', ['login' => 'bob', 'password' => 'Passw0rd', 'name' => 'Bob']));

        $this->assertSame(0, $this->tokenCount());
    }

    private function assertConnectInfo(array $info, int $userId, ApiClient $client): void
    {
        $this->assertSame(['url', 'token', 'expiresIn'], array_keys($info));
        $this->assertSame(WEBSOCKET_URL, $info['url']);
        $this->assertSame(WEBSOCKET_TOKEN_TTL, $info['expiresIn']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $info['token']));

        $stmt = DB::get()->prepare('SELECT user_id, session_id FROM websocket_tokens WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $info['token'])]);
        $row = $stmt->fetch();
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame($client->cookies['PHPSESSID'], $row['session_id']);
    }

    private function tokenCount(): int
    {
        return (int) DB::get()->query('SELECT COUNT(*) FROM websocket_tokens')->fetchColumn();
    }
}
