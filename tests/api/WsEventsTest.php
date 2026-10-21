<?php

require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

/** События сервера, которые рассылают REST-методы */
class WsEventsTest extends TestCase
{
    private ApiClient $client;
    private int $me;
    private ApiClient $bobClient;
    private int $bob;

    public function setUp(): void
    {
        parent::setUp();
        [$this->client, $user] = $this->signup('me', 'Me');
        $this->me = $user['userId'];
        [$this->bobClient, $bob] = $this->signup('bob', 'Bob');
        $this->bob = $bob['userId'];
    }

    // ---- conversations / singleCreated ----

    public function testSingleCreated(): void
    {
        $mine = $this->wsConnect($this->client, $this->me);
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $res = $this->client->post('/conversations/createSingle', ['userId' => $this->bob]);
        $id  = $res['response']['conversation']['conversationId'];

        // Каждому участнику — переписка с его точки зрения
        $this->assertEvent('conversations', 'singleCreated',
            ['conversation' => Conversation::findById($id, $this->me)], $mine->receive(), [$this->me, $this->bob]);
        $this->assertEvent('conversations', 'singleCreated',
            ['conversation' => Conversation::findById($id, $this->bob)], $bobs->receive(), [$this->me, $this->bob]);
        $this->assertSame($res['response']['conversation'], Conversation::findById($id, $this->me));
    }

    public function testSingleCreatedNotSentForExisting(): void
    {
        $this->client->post('/conversations/createSingle', ['userId' => $this->bob]);
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $this->client->post('/conversations/createSingle', ['userId' => $this->bob]);
        $this->bobClient->post('/conversations/createSingle', ['userId' => $this->me]);

        $this->assertNull($bobs->receive(0.3));
    }

    public function testSingleCreatedNotSentToOthers(): void
    {
        [$carolClient, $carol] = $this->signup('carol');
        $carols = $this->wsConnect($carolClient, $carol['userId']);

        $this->client->post('/conversations/createSingle', ['userId' => $this->bob]);

        $this->assertNull($carols->receive(0.3));
    }

    // ---- messages / received ----

    public function testMessageReceived(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $mine = $this->wsConnect($this->client, $this->me);
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $message = $this->client->post('/messages/send', ['conversationId' => $conversationId, 'text' => 'Привет', 'clientId' => 'c1'])['response']['message'];

        // Отправитель тоже получает событие; lastMessage — новое последнее сообщение переписки,
        // readInfo — у каждого получателя свой
        $body = ['message' => $message, 'lastMessage' => Message::toLastMessage($message)];
        $this->assertEvent('messages', 'received', $body + ['readInfo' => [
            'conversationId' => $conversationId, 'lastReadMessageId' => 0, 'unreadCount' => 1, 'totalUnreadCount' => 1, 'changeVersion' => 2,
        ]], $bobs->receive(), [$this->me]);
        $this->assertEvent('messages', 'received', $body + ['readInfo' => [
            'conversationId' => $conversationId, 'lastReadMessageId' => $message['messageId'], 'unreadCount' => 0, 'totalUnreadCount' => 0, 'changeVersion' => 2,
        ]], $mine->receive(), [$this->me]);
        $this->assertSame($body['lastMessage'], Conversation::findById($conversationId, $this->bob)['lastMessage']);
    }

    public function testMessageReceivedOnAllConnectionsOfUser(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $first  = $this->wsConnect($this->bobClient, $this->bob);
        $second = $this->wsConnect($this->bobClient, $this->bob);

        $this->client->post('/messages/send', ['conversationId' => $conversationId, 'text' => 'Hi', 'clientId' => 'c2']);

        $a = $first->receive();
        $b = $second->receive();
        $this->assertSame('received', $a['event'] ?? null);
        $this->assertSame('received', $b['event'] ?? null);
        // У каждого отправленного сообщения свой uid
        $this->assertFalse($a['uid'] === $b['uid']);
    }

    public function testMessageAcknowledged(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $this->client->post('/messages/send', ['conversationId' => $conversationId, 'text' => 'Hi', 'clientId' => 'c3']);
        $bobs->ack($bobs->receive());

        $this->assertTrue($bobs->ping());
        $this->assertNull($bobs->closeCode);
    }

    public function testMessageReceivedNotSentOnRetry(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $params = ['conversationId' => $conversationId, 'text' => 'Hi', 'clientId' => 'retry'];
        $this->assertSuccess($this->client->post('/messages/send', $params));
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $this->assertSuccess($this->client->post('/messages/send', $params));

        $this->assertNull($bobs->receive(0.3));
    }

    // ---- messages / edited ----

    public function testMessageEdited(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $messageId = Message::send($conversationId, $this->me, 'Hi')['messageId'];
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $res = $this->client->post('/messages/edit', ['messageId' => $messageId, 'newText' => 'Hi!'])['response'];

        // Отредактировано последнее сообщение — в событии новый lastMessage, как и в ответе,
        // и новая версия получателя
        $this->assertEvent('messages', 'edited', $res + ['changeVersion' => 3], $bobs->receive(), [$this->me]);
        $this->assertSame(3, Conversation::findById($conversationId, $this->bob)['changeVersion']);
        $this->assertSame(Message::toLastMessage($res['message']), $res['lastMessage']);
    }

    public function testMessageEditedNotLastHasNoLastMessage(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $messageId = Message::send($conversationId, $this->me, 'Hi')['messageId'];
        Message::send($conversationId, $this->bob, 'Hello');
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $message = $this->client->post('/messages/edit', ['messageId' => $messageId, 'newText' => 'Hi!'])['response']['message'];

        $this->assertEvent('messages', 'edited', ['message' => $message], $bobs->receive(), [$this->me]);
    }

    public function testMessageEditedNotSentWhenTextUnchanged(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $messageId = Message::send($conversationId, $this->me, 'Hi')['messageId'];
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $this->client->post('/messages/edit', ['messageId' => $messageId, 'newText' => ' Hi ']);

        $this->assertNull($bobs->receive(0.3));
    }

    // ---- conversations / read ----

    public function testRead(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $messageId = Message::send($conversationId, $this->me, 'Hi')['messageId'];
        $first  = $this->wsConnect($this->bobClient, $this->bob);
        $second = $this->wsConnect($this->bobClient, $this->bob);
        $mine   = $this->wsConnect($this->client, $this->me);

        $readInfo = $this->bobClient->post('/conversations/markRead', ['conversationId' => $conversationId, 'messageId' => $messageId])['response']['readInfo'];

        // Во все соединения читающего, включая вызвавшее; собеседнику — нет
        $this->assertEvent('conversations', 'read', ['readInfo' => $readInfo], $first->receive());
        $this->assertEvent('conversations', 'read', ['readInfo' => $readInfo], $second->receive());
        $this->assertNull($mine->receive(0.3));
    }

    public function testReadNotSentWhenNothingChanged(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $messageId = Message::send($conversationId, $this->me, 'Hi')['messageId'];
        Conversation::markRead($conversationId, $this->bob, $messageId);
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $this->assertSuccess($this->bobClient->post('/conversations/markRead', ['conversationId' => $conversationId, 'messageId' => $messageId]));

        $this->assertNull($bobs->receive(0.3));
    }

    /** Правило непрерывности: каждое изменение приходит событием ровно со своей версией, без пропусков. */
    public function testEventVersionsAreContiguous(): void
    {
        [$carolClient, $carol] = $this->signup('carol');
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $withMe    = $this->client->post('/conversations/createSingle', ['userId' => $this->bob])['response']['conversation']['conversationId'];
        $withCarol = $carolClient->post('/conversations/createSingle', ['userId' => $this->bob])['response']['conversation']['conversationId'];
        $message   = $this->client->post('/messages/send', ['conversationId' => $withMe, 'text' => 'Hi', 'clientId' => 'c1'])['response']['message'];
        $carolClient->post('/messages/send', ['conversationId' => $withCarol, 'text' => 'Hi', 'clientId' => 'c2']);
        $this->client->post('/messages/edit', ['messageId' => $message['messageId'], 'newText' => 'Hi!']);
        $this->bobClient->post('/conversations/markRead', ['conversationId' => $withMe, 'messageId' => $message['messageId']]);
        $this->bobClient->post('/messages/send', ['conversationId' => $withCarol, 'text' => 'Reply', 'clientId' => 'c3']);

        $versions = [];
        for ($i = 0; $i < 7; $i++) {
            $event = $bobs->receive();
            $body  = $event['body'];
            $versions[] = $body['readInfo']['changeVersion'] ?? $body['conversation']['changeVersion'] ?? $body['changeVersion'] ?? null;
        }
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $versions);
        $this->assertSame(7, Conversation::findTotalUnreadCount($this->bob)['changeVersion']);
        $this->assertUnreadInvariant();
    }

    public function testPendingConnectionGetsNoEvents(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $pending = $this->ws();

        $this->assertSuccess($this->client->post('/messages/send', ['conversationId' => $conversationId, 'text' => 'Hi', 'clientId' => 'c4']));
        $this->assertNull($pending->receive(0.3));
    }
}
