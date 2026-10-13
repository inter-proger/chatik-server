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

        // Отправитель тоже получает событие; lastMessage — новое последнее сообщение переписки
        $body = ['message' => $message, 'lastMessage' => Message::toLastMessage($message)];
        $this->assertEvent('messages', 'received', $body, $bobs->receive(), [$this->me]);
        $this->assertEvent('messages', 'received', $body, $mine->receive(), [$this->me]);
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

        // Отредактировано последнее сообщение — в событии новый lastMessage, как и в ответе
        $this->assertEvent('messages', 'edited', $res, $bobs->receive(), [$this->me]);
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

    public function testPendingConnectionGetsNoEvents(): void
    {
        $conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
        $pending = $this->ws();

        $this->assertSuccess($this->client->post('/messages/send', ['conversationId' => $conversationId, 'text' => 'Hi', 'clientId' => 'c4']));
        $this->assertNull($pending->receive(0.3));
    }
}
