<?php

require_once __DIR__ . '/../../core/WebSocket.php';

class WebSocketTest extends TestCase
{
    public function testIssueToken(): void
    {
        $userId = $this->insertUser('a');

        $info = WebSocket::issueToken($userId, 'sess1');

        $this->assertSame(['url', 'token', 'expiresIn'], array_keys($info));
        $this->assertSame(WEBSOCKET_URL, $info['url']);
        $this->assertSame(WEBSOCKET_TOKEN_TTL, $info['expiresIn']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $info['token']));

        $row = DB::get()->query('SELECT token_hash, user_id, session_id, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS ttl FROM websocket_tokens')->fetch();
        $this->assertSame(hash('sha256', $info['token']), $row['token_hash']);
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame('sess1', $row['session_id']);
        $this->assertTrue(abs((int) $row['ttl'] - WEBSOCKET_TOKEN_TTL) <= 1);
    }

    public function testIssueTokenDeletesExpired(): void
    {
        $userId = $this->insertUser('a');
        WebSocket::issueToken($userId, 's');
        DB::get()->exec('UPDATE websocket_tokens SET expires_at = NOW() - INTERVAL 1 SECOND');

        WebSocket::issueToken($userId, 's');

        $this->assertSame(1, (int) DB::get()->query('SELECT COUNT(*) FROM websocket_tokens')->fetchColumn());
    }

    public function testConsumeTokenOnce(): void
    {
        $userId = $this->insertUser('a');
        $token  = WebSocket::issueToken($userId, 'sess1')['token'];

        $this->assertSame('sess1', WebSocket::consumeToken($token, $userId));
        $this->assertNull(WebSocket::consumeToken($token, $userId));
    }

    public function testConsumeTokenOfOtherUserBurnsIt(): void
    {
        $a     = $this->insertUser('a');
        $b     = $this->insertUser('b');
        $token = WebSocket::issueToken($a, 's')['token'];

        $this->assertNull(WebSocket::consumeToken($token, $b));
        $this->assertNull(WebSocket::consumeToken($token, $a));
    }

    public function testConsumeExpiredToken(): void
    {
        $userId = $this->insertUser('a');
        $token  = WebSocket::issueToken($userId, 's')['token'];
        DB::get()->exec('UPDATE websocket_tokens SET expires_at = NOW() - INTERVAL 1 SECOND');

        $this->assertNull(WebSocket::consumeToken($token, $userId));
        $this->assertSame(0, (int) DB::get()->query('SELECT COUNT(*) FROM websocket_tokens')->fetchColumn());
    }

    public function testConsumeUnknownToken(): void
    {
        $this->assertNull(WebSocket::consumeToken('nope', 1));
    }

    public function testEndSessionRevokesOnlyItsTokens(): void
    {
        $userId = $this->insertUser('a');
        $mine   = WebSocket::issueToken($userId, 'mine')['token'];
        $other  = WebSocket::issueToken($userId, 'other')['token'];

        WebSocket::endSession('mine');

        $this->assertNull(WebSocket::consumeToken($mine, $userId));
        $this->assertSame('other', WebSocket::consumeToken($other, $userId));
    }

    public function testMessage(): void
    {
        $this->assertSame(
            ['group' => 'messages', 'event' => 'received', 'body' => ['x' => 1], 'userIds' => [1, 3]],
            json_decode(json_encode(WebSocket::message('messages', 'received', ['x' => 1], [3, '1', 3])), true)
        );
        // Пустой body — объект, пустой userIds не попадает в сообщение
        $this->assertSame('{"group":"g","event":"e","body":{}}', json_encode(WebSocket::message('g', 'e', [])));
    }

    public function testDelivery(): void
    {
        $delivery = WebSocket::delivery([2, 1, 2], 'g', 'e', [], [5]);

        $this->assertSame([1, 2], $delivery['recipientIds']);
        $this->assertSame([5], $delivery['message']['userIds']);
    }
}
