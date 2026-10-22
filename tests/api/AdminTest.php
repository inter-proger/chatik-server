<?php

require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

class AdminTest extends TestCase
{
    private ApiClient $admin;

    public function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->client();
        $this->assertSuccess($this->admin->post('/admin/login', ['login' => 'admin', 'password' => 'admin']));
    }

    // ---- Страница и вход ----

    public function testPage(): void
    {
        $html = file_get_contents(self::$baseUrl . '/admin');

        $this->assertTrue(str_contains($html, '<title>ServerAi — админка</title>'));
    }

    public function testLoginWrongCredentials(): void
    {
        foreach ([[], ['login' => 'admin', 'password' => 'wrong'], ['login' => 'root', 'password' => 'admin'], ['login' => ['admin'], 'password' => 'admin']] as $params) {
            $client = $this->client();
            $this->assertError(ErrorCode::WRONG_CREDENTIALS, $client->post('/admin/login', $params));
            $this->assertError(ErrorCode::NOT_AUTHENTICATED, $client->get('/admin/getUsers'));
        }
    }

    public function testNotAuthenticated(): void
    {
        $client = $this->client();

        foreach (['/admin/getUsers', '/admin/getConversations', '/admin/sendMessage', '/admin/markRead', '/admin/logout'] as $path) {
            $this->assertError(ErrorCode::NOT_AUTHENTICATED, $client->post($path));
        }
    }

    public function testUserSessionIsNotAdmin(): void
    {
        [$client] = $this->signup('me');

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $client->get('/admin/getUsers'));
    }

    public function testAdminSessionIsSeparateFromUserSession(): void
    {
        [$client, $user] = $this->signup('me');
        $this->assertSuccess($client->post('/admin/login', ['login' => 'admin', 'password' => 'admin']));

        $this->assertSuccess($client->get('/admin/getUsers'));
        $this->assertSame($user, $client->get('/profile/get')['response']);

        $this->assertSuccess($client->post('/admin/logout'));
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $client->get('/admin/getUsers'));
        $this->assertSuccess($client->get('/profile/get'));
    }

    // ---- /admin/getUsers ----

    public function testGetUsers(): void
    {
        $alice = $this->insertUser('alice', 'Alice');
        $this->insertUser('bob', 'Bob');
        $this->insertUser('malice', 'Someone');

        $res = $this->admin->get('/admin/getUsers');
        $this->assertSuccess($res);
        $this->assertSame(['alice', 'bob', 'malice'], array_column($res['response']['users'], 'login'));
        $this->assertSame(User::findById($alice), $res['response']['users'][0]);
        $this->assertFalse($res['response']['hasNext']);

        $res = $this->admin->get('/admin/getUsers', ['query' => ' lic ']);
        $this->assertSame(['alice', 'malice'], array_column($res['response']['users'], 'login'));
    }

    public function testGetUsersLimit(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->insertUser("u$i");
        }

        $res = $this->admin->get('/admin/getUsers');

        $this->assertCount(50, $res['response']['users']);
        $this->assertTrue($res['response']['hasNext']);
    }

    public function testGetUsersInvalidParams(): void
    {
        $this->assertError(ErrorCode::INVALID_PARAMS, $this->admin->get('/admin/getUsers', ['query' => ['a']]));
    }

    // ---- /admin/getConversations ----

    public function testGetConversations(): void
    {
        $me    = $this->insertUser('me', 'Me');
        $bob   = $this->insertUser('bob', 'Bob');
        $carol = $this->insertUser('carol', 'Carol');
        $this->insertUser('dave');
        $withBob   = Conversation::findOrCreateSingle($me, $bob)['conversationId'];
        $withCarol = Conversation::findOrCreateSingle($carol, $me)['conversationId'];
        Conversation::findOrCreateSingle($bob, $carol);

        $res = $this->admin->get('/admin/getConversations', ['userId' => $me]);

        $this->assertSuccess($res);
        $this->assertSame([
            Conversation::findById($withCarol, $me),
            Conversation::findById($withBob, $me),
        ], $res['response']['conversations']);
        $this->assertSame($carol, $res['response']['conversations'][0]['opponentUserId']);
        $this->assertSame(array_values(User::findByIds([$me, $bob, $carol])), $res['response']['users']);
        $this->assertFalse($res['response']['hasNext']);
    }

    public function testGetConversationsPaging(): void
    {
        $me = $this->insertUser('me');
        for ($i = 0; $i < 3; $i++) {
            Conversation::findOrCreateSingle($me, $this->insertUser("u$i"));
        }

        $res = $this->admin->get('/admin/getConversations', ['userId' => $me, 'count' => 2]);
        $this->assertSame([3, 2], array_column($res['response']['conversations'], 'conversationId'));
        $this->assertTrue($res['response']['hasNext']);

        $res = $this->admin->get('/admin/getConversations', ['userId' => $me, 'offset' => 2, 'count' => 2]);
        $this->assertSame([1], array_column($res['response']['conversations'], 'conversationId'));
        $this->assertFalse($res['response']['hasNext']);
    }

    public function testGetConversationsEmpty(): void
    {
        $me = $this->insertUser('me');

        $res = $this->admin->get('/admin/getConversations', ['userId' => $me]);

        $this->assertSame(['conversations' => [], 'users' => [], 'hasNext' => false], $res['response']);
    }

    public function testGetConversationsUserNotFound(): void
    {
        foreach ([[], ['userId' => ''], ['userId' => 'abc'], ['userId' => '-1'], ['userId' => '999'], ['userId' => ['1']]] as $params) {
            $this->assertError(ErrorCode::USER_NOT_FOUND, $this->admin->get('/admin/getConversations', $params));
        }
    }

    public function testGetConversationsInvalidParams(): void
    {
        $me = $this->insertUser('me');

        foreach ([['offset' => '-1'], ['offset' => 'a'], ['count' => '0'], ['count' => 'a'], ['count' => ['1']]] as $params) {
            $res = $this->admin->get('/admin/getConversations', ['userId' => $me] + $params);
            $this->assertError(ErrorCode::INVALID_PARAMS, $res);
        }
    }

    // ---- /admin/sendMessage ----

    public function testSendMessage(): void
    {
        [$meClient, $meUser] = $this->signup('me', 'Me');
        [$bobClient, $bobUser] = $this->signup('bob', 'Bob');
        $me  = $meUser['userId'];
        $bob = $bobUser['userId'];
        $conversationId = Conversation::findOrCreateSingle($me, $bob)['conversationId'];
        $meWs  = $this->wsConnect($meClient, $me);
        $bobWs = $this->wsConnect($bobClient, $bob);

        $res = $this->admin->post('/admin/sendMessage', ['userId' => $me, 'conversationId' => $conversationId, 'text' => '  Hello  ']);

        $this->assertSuccess($res);
        $message = $res['response']['message'];
        $this->assertSame(Message::findById($message['messageId']), $message);
        $this->assertSame($me, $message['senderId']);
        $this->assertSame($conversationId, $message['conversationId']);
        $this->assertSame('Hello', $message['text']);
        $this->assertFalse(isset($res['actions']));

        // Событие получают все участники — в том числе сам отправитель, он отправлял не со своего устройства
        $readInfos = Conversation::findReadInfos($conversationId);
        foreach ([$me => $meWs, $bob => $bobWs] as $userId => $ws) {
            $this->assertEvent('messages', 'received', [
                'message' => $message, 'lastMessage' => Message::toLastMessage($message), 'readInfo' => $readInfos[$userId],
            ], $ws->receive(), [$me]);
        }
        $this->assertSame($message['messageId'], $readInfos[$me]['lastReadMessageId']);
        $this->assertSame(1, $readInfos[$bob]['unreadCount']);
    }

    public function testSendMessageUserNotFound(): void
    {
        foreach (['', 'abc', '-1', '999'] as $userId) {
            $res = $this->admin->post('/admin/sendMessage', ['userId' => $userId, 'conversationId' => 1, 'text' => 'Hi']);
            $this->assertError(ErrorCode::USER_NOT_FOUND, $res);
        }
    }

    public function testSendMessageConversationNotFound(): void
    {
        $me    = $this->insertUser('me');
        $bob   = $this->insertUser('bob');
        $carol = $this->insertUser('carol');
        $foreign = Conversation::findOrCreateSingle($bob, $carol)['conversationId'];

        foreach (['', 'abc', '-1', '999', (string) $foreign] as $conversationId) {
            $res = $this->admin->post('/admin/sendMessage', ['userId' => $me, 'conversationId' => $conversationId, 'text' => 'Hi']);
            $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $res);
        }
        $this->assertNull(Message::findById(1));
    }

    public function testSendMessageInvalidText(): void
    {
        $me  = $this->insertUser('me');
        $conversationId = Conversation::findOrCreateSingle($me, $this->insertUser('bob'))['conversationId'];

        foreach ([[], ['text' => '   '], ['text' => str_repeat('a', Message::TEXT_MAX_LENGTH + 1)], ['text' => ['a']]] as $params) {
            $res = $this->admin->post('/admin/sendMessage', ['userId' => $me, 'conversationId' => $conversationId] + $params);
            $this->assertError(ErrorCode::INVALID_TEXT, $res);
        }
    }

    // ---- /admin/markRead ----

    public function testMarkRead(): void
    {
        [$meClient, $meUser] = $this->signup('me', 'Me');
        [$bobClient, $bobUser] = $this->signup('bob', 'Bob');
        $me  = $meUser['userId'];
        $bob = $bobUser['userId'];
        $conversationId = Conversation::findOrCreateSingle($me, $bob)['conversationId'];
        Message::send($conversationId, $bob, 'One');
        $last = Message::send($conversationId, $bob, 'Two')['messageId'];
        $meWs  = $this->wsConnect($meClient, $me);
        $bobWs = $this->wsConnect($bobClient, $bob);

        $res = $this->admin->post('/admin/markRead', ['userId' => $me, 'conversationId' => $conversationId]);

        // До последнего сообщения; события — как в /conversations/markRead
        $this->assertSuccess($res);
        $readInfo = $res['response']['readInfo'];
        $this->assertSame(Conversation::findReadInfos($conversationId, [$me])[$me], $readInfo);
        $this->assertSame($last, $readInfo['lastReadMessageId']);
        $this->assertSame(0, $readInfo['unreadCount']);
        $this->assertSame(0, $readInfo['totalUnreadCount']);
        $this->assertFalse(isset($res['actions']));
        $this->assertEvent('conversations', 'read', ['readInfo' => $readInfo], $meWs->receive());
        $this->assertEvent('conversations', 'memberRead', [
            'conversationId' => $conversationId, 'userId' => $me, 'lastReadMessageId' => $last,
        ], $bobWs->receive());
        $this->assertUnreadInvariant();

        // Повтор ничего не меняет — событий нет
        $this->assertSame($readInfo, $this->admin->post('/admin/markRead', ['userId' => $me, 'conversationId' => $conversationId])['response']['readInfo']);
        $this->assertNull($meWs->receive(0.3));
        $this->assertNull($bobWs->receive(0.3));
    }

    public function testMarkReadEmptyConversation(): void
    {
        [$meClient, $meUser] = $this->signup('me', 'Me');
        $me = $meUser['userId'];
        $conversationId = Conversation::findOrCreateSingle($me, $this->insertUser('bob'))['conversationId'];
        $meWs = $this->wsConnect($meClient, $me);

        $res = $this->admin->post('/admin/markRead', ['userId' => $me, 'conversationId' => $conversationId]);

        $this->assertSuccess($res);
        $this->assertSame(Conversation::findReadInfos($conversationId, [$me])[$me], $res['response']['readInfo']);
        $this->assertSame(0, $res['response']['readInfo']['lastReadMessageId']);
        $this->assertNull($meWs->receive(0.3));
    }

    public function testMarkReadUserNotFound(): void
    {
        foreach (['', 'abc', '-1', '999'] as $userId) {
            $res = $this->admin->post('/admin/markRead', ['userId' => $userId, 'conversationId' => 1]);
            $this->assertError(ErrorCode::USER_NOT_FOUND, $res);
        }
    }

    public function testMarkReadConversationNotFound(): void
    {
        $me    = $this->insertUser('me');
        $bob   = $this->insertUser('bob');
        $carol = $this->insertUser('carol');
        $foreign = Conversation::findOrCreateSingle($bob, $carol)['conversationId'];

        foreach (['', 'abc', '-1', '999', (string) $foreign] as $conversationId) {
            $res = $this->admin->post('/admin/markRead', ['userId' => $me, 'conversationId' => $conversationId]);
            $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $res);
        }
    }
}
