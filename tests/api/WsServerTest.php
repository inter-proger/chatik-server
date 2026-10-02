<?php

require_once __DIR__ . '/../../core/WebSocket.php';

/** WebSocket-сервер: подключение, авторизация, протокол, heartbeat, закрытие сессии, лимиты */
class WsServerTest extends TestCase
{
    private ApiClient $client;
    private int $me;

    public function setUp(): void
    {
        parent::setUp();
        [$this->client, $user] = $this->signup('me', 'Me');
        $this->me = $user['userId'];
    }

    // ---- Подключение ----

    public function testConnect(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);

        $this->assertTrue($ws->ping());
    }

    public function testConnectWithTokenFromStart(): void
    {
        $info = $this->client->get('/global/start')['response']['websocketConnectInfo'];
        $ws   = $this->ws();

        $ws->send('auth', 'connect', ['userId' => $this->me, 'token' => $info['token']]);

        $this->assertEvent('auth', 'connected', [], $ws->receive());
    }

    public function testHandshakeRejected(): void
    {
        $socket = stream_socket_client(str_replace('ws://', 'tcp://', WEBSOCKET_URL));
        fwrite($socket, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");

        $this->assertStringStartsWith('HTTP/1.1 400', (string) fgets($socket));
        fclose($socket);
    }

    public function testTokenIsSingleUse(): void
    {
        $token = $this->wsToken($this->client);
        $first = $this->ws();
        $first->send('auth', 'connect', ['userId' => $this->me, 'token' => $token]);
        $this->assertEvent('auth', 'connected', [], $first->receive());

        $second = $this->ws();
        $second->send('auth', 'connect', ['userId' => $this->me, 'token' => $token]);

        $this->assertConnectionError(ErrorCode::WS_TOKEN_INVALID, $second);
    }

    public function testTokenOfOtherUserIsBurned(): void
    {
        [, $bob] = $this->signup('bob');
        $token = $this->wsToken($this->client);

        $ws = $this->ws();
        $ws->send('auth', 'connect', ['userId' => $bob['userId'], 'token' => $token]);
        $this->assertConnectionError(ErrorCode::WS_TOKEN_INVALID, $ws);

        $ws = $this->ws();
        $ws->send('auth', 'connect', ['userId' => $this->me, 'token' => $token]);
        $this->assertConnectionError(ErrorCode::WS_TOKEN_INVALID, $ws);
    }

    public function testExpiredToken(): void
    {
        $token = $this->wsToken($this->client);
        DB::get()->exec('UPDATE websocket_tokens SET expires_at = NOW() - INTERVAL 1 SECOND');

        $ws = $this->ws();
        $ws->send('auth', 'connect', ['userId' => $this->me, 'token' => $token]);

        $this->assertConnectionError(ErrorCode::WS_TOKEN_INVALID, $ws);
    }

    public function testRevokedToken(): void
    {
        $token = $this->wsToken($this->client);
        $this->client->post('/auth/logout');

        $ws = $this->ws();
        $ws->send('auth', 'connect', ['userId' => $this->me, 'token' => $token]);

        $this->assertConnectionError(ErrorCode::WS_TOKEN_INVALID, $ws);
    }

    public function testConnectInvalidParams(): void
    {
        $token = $this->wsToken($this->client);

        foreach ([
            ['token' => $token],
            ['userId' => $this->me],
            ['userId' => (string) $this->me, 'token' => $token],
            ['userId' => 0, 'token' => $token],
            ['userId' => $this->me, 'token' => 123],
            ['userId' => $this->me, 'token' => ''],
        ] as $body) {
            $ws = $this->ws();
            $ws->send('auth', 'connect', $body);
            $this->assertConnectionError(ErrorCode::INVALID_PARAMS, $ws);
        }

        // Невалидные параметры токен не гасят
        $this->wsConnect($this->client, $this->me);
    }

    public function testAuthTimeout(): void
    {
        $ws = $this->ws();

        $this->assertNull($ws->receive(self::WS_AUTH_TIMEOUT + 1));
        $this->assertSame(4002, $ws->closeCode);
    }

    // ---- Ошибки протокола ----

    public function testFirstMessageMustBeConnect(): void
    {
        $ws = $this->ws();
        $ws->send('events', 'received', ['receivedUid' => 'x']);

        $this->assertClosed(4000, $ws);
    }

    public function testInvalidMessages(): void
    {
        foreach (['not json', '{}', '{"uid":"u","group":"auth","event":"connect","body":[]}'] as $text) {
            $ws = $this->wsConnect($this->client, $this->me);
            $ws->sendText($text);
            $this->assertClosed(4000, $ws);
        }
    }

    public function testBinaryFrame(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);
        $ws->sendFrame(WsFrame::OP_BINARY, 'x');

        $this->assertClosed(4000, $ws);
    }

    public function testRepeatedConnect(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);
        $ws->send('auth', 'connect', ['userId' => $this->me, 'token' => $this->wsToken($this->client)]);

        $this->assertClosed(4000, $ws);
    }

    public function testMessageTooBig(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);
        $ws->sendText(str_repeat('a', WEBSOCKET_MAX_MESSAGE_SIZE + 1));

        $this->assertClosed(1009, $ws);
    }

    public function testFragmentedMessage(): void
    {
        $ws   = $this->ws();
        $json = json_encode(['uid' => 'u', 'group' => 'auth', 'event' => 'connect', 'body' => ['userId' => $this->me, 'token' => $this->wsToken($this->client)]]);

        $ws->sendFrame(WsFrame::OP_TEXT, substr($json, 0, 10), false);
        $ws->sendFrame(WsFrame::OP_PING, 'between');
        $ws->sendFrame(WsFrame::OP_CONTINUATION, substr($json, 10));

        $this->assertEvent('auth', 'connected', [], $ws->receive());
    }

    public function testUnknownEventsAndAcksAreIgnored(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);

        $ws->send('unknown', 'event', ['a' => 1]);
        $ws->send('events', 'received', ['receivedUid' => 'not-sent']);
        $ws->send('events', 'received', []);

        $this->assertTrue($ws->ping());
        $this->assertNull($ws->receive(0.2));
        $this->assertNull($ws->closeCode);
    }

    // ---- Heartbeat, закрытие ----

    public function testIdleConnectionIsDropped(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);

        $this->assertNull($ws->receive(self::WS_IDLE_TIMEOUT + 1));
        $this->assertSame(1006, $ws->closeCode);
    }

    public function testPingKeepsConnectionAlive(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);

        $until = microtime(true) + self::WS_IDLE_TIMEOUT + 1;
        while (microtime(true) < $until) {
            $this->assertTrue($ws->ping());
            usleep(500_000);
        }
        $this->assertNull($ws->closeCode);
    }

    public function testClientClose(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);
        $ws->sendFrame(WsFrame::OP_CLOSE, WsFrame::closePayload(1000));

        $this->assertClosed(1000, $ws);
    }

    public function testLogoutClosesSessionConnections(): void
    {
        $mine = $this->wsConnect($this->client, $this->me);

        // Та же учётная запись в другой сессии
        $otherSession = $this->client();
        $otherSession->post('/auth/login', ['login' => 'me', 'password' => 'Passw0rd']);
        $other = $this->wsConnect($otherSession, $this->me);

        $this->client->post('/auth/logout');

        $this->assertClosed(4003, $mine);
        $this->assertTrue($other->ping());
    }

    public function testLoginInSameSessionClosesOldConnections(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);
        $this->insertUser('bob');

        $this->client->post('/auth/login', ['login' => 'bob', 'password' => 'Passw0rd']);

        $this->assertClosed(4003, $ws);
    }

    // ---- Лимиты ----

    public function testConnectionLimit(): void
    {
        $connections = [];
        for ($i = 0; $i < self::WS_MAX_CONNECTIONS; $i++) {
            $connections[] = $this->ws();
        }

        try {
            $this->ws();
            $this->fail('Connection over the limit was accepted');
        } catch (RuntimeException) {
        }

        // Освободившееся место снова доступно
        $connections[0]->disconnect();
        usleep(100_000);
        $this->assertTrue($this->wsConnect($this->client, $this->me)->ping());
    }

    public function testOutputBufferOverflowDropsConnection(): void
    {
        $ws = $this->wsConnect($this->client, $this->me);
        [$bobClient, $bob] = $this->signup('bob');
        $other = $this->wsConnect($bobClient, $bob['userId']);

        // Один publish кладёт все события в буфер до записи в сокет — так же копятся события
        // у клиента, который не читает сокет
        $data = str_repeat('x', 1024);
        WebSocket::publishEach(array_fill(0, (int) (self::WS_MAX_OUTPUT_BUFFER / 1024) + 1,
            WebSocket::delivery([$this->me], 'test', 'big', ['data' => $data])));

        $this->assertNull($ws->receive());
        $this->assertSame(1006, $ws->closeCode);
        $this->assertTrue($other->ping());
    }

    private function assertConnectionError(int $errorCode, WsClient $ws): void
    {
        $this->assertEvent('auth', 'connectionError', ['errorCode' => $errorCode], $ws->receive());
        $this->assertClosed(4001, $ws);
    }
}
