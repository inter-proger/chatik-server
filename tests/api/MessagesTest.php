<?php

require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

class MessagesTest extends TestCase
{
    private ApiClient $client;
    private int $me;
    private ApiClient $bobClient;
    private int $bob;
    private int $conversationId;

    public function setUp(): void
    {
        parent::setUp();
        [$this->client, $user] = $this->signup('me', 'Me');
        $this->me = $user['userId'];
        [$this->bobClient, $bob] = $this->signup('bob', 'Bob');
        $this->bob = $bob['userId'];
        $this->conversationId = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];
    }

    // ---- /messages/send ----

    public function testSend(): void
    {
        $res = $this->client->post('/messages/send', [
            'conversationId' => $this->conversationId, 'text' => '  Hello  ', 'clientId' => 'local-1',
        ]);

        $this->assertSuccess($res);
        $message = $res['response']['message'];
        $this->assertSame(Message::findById($message['messageId']), $message);
        $this->assertSame('local-1', $message['clientId']);
        $this->assertSame($this->conversationId, $message['conversationId']);
        $this->assertSame($this->me, $message['senderId']);
        $this->assertSame('text', $message['type']);
        $this->assertSame('Hello', $message['text']);
        $this->assertFalse($message['isEdited']);
        $this->assertSame(['message'], array_keys($res['response']));
        $this->assertUsersAction([$this->me], $res);
    }

    public function testSendRaisesConversation(): void
    {
        $before = Conversation::findById($this->conversationId, $this->me);

        $first  = $this->send($this->client, 'one');
        $second = $this->send($this->bobClient, 'two');

        $conversation = Conversation::findById($this->conversationId, $this->me);
        $this->assertSame(Message::toLastMessage($second), $conversation['lastMessage']);
        $this->assertSame($second['createdAt'], $conversation['positionUpdatedAt']);
        $this->assertTrue($conversation['positionUpdatedAt'] >= $first['createdAt']);
        $this->assertSame($before['updatedInfoAt'], $conversation['updatedInfoAt']);
    }

    public function testSendRetryDoesNotTouchConversation(): void
    {
        $params = ['conversationId' => $this->conversationId, 'text' => 'one', 'clientId' => 'retry'];
        $first  = $this->client->post('/messages/send', $params)['response']['message'];
        $second = $this->send($this->bobClient, 'two');

        $this->assertSame($first, $this->client->post('/messages/send', $params)['response']['message']);
        $this->assertSame($second['messageId'], Conversation::findById($this->conversationId, $this->me)['lastMessage']['messageId']);
    }

    public function testSendInvalidText(): void
    {
        foreach ([[], ['text' => ''], ['text' => '   '], ['text' => str_repeat('a', Message::TEXT_MAX_LENGTH + 1)], ['text' => ['a']]] as $params) {
            $res = $this->client->post('/messages/send', ['conversationId' => $this->conversationId, 'clientId' => 'c1'] + $params);
            $this->assertError(ErrorCode::INVALID_TEXT, $res);
        }
    }

    public function testSendInvalidClientId(): void
    {
        $invalid = [[], ['clientId' => ''], ['clientId' => str_repeat('a', Message::CLIENT_ID_MAX_LENGTH + 1)],
            ['clientId' => 'a b'], ['clientId' => 'ид'], ['clientId' => "a\n"], ['clientId' => ['a']]];
        foreach ($invalid as $params) {
            $res = $this->client->post('/messages/send', ['conversationId' => $this->conversationId, 'text' => 'Hi'] + $params);
            $this->assertError(ErrorCode::INVALID_CLIENT_ID, $res);
        }
        $this->assertNull(Message::findById(1));

        $clientId = str_repeat('A', Message::CLIENT_ID_MAX_LENGTH - 2) . '-_';
        $this->assertSame($clientId, $this->send($this->client, 'Hi', $clientId)['clientId']);
    }

    public function testSendRetryReturnsSameMessage(): void
    {
        $message = $this->send($this->client, 'Hello', 'retry');

        // Повтор (в т. ч. с другим текстом) не создаёт новое сообщение
        $res = $this->client->post('/messages/send', ['conversationId' => $this->conversationId, 'text' => 'Other', 'clientId' => 'retry']);

        $this->assertSuccess($res);
        $this->assertSame($message, $res['response']['message']);
        $this->assertUsersAction([$this->me], $res);
        $this->assertNull(Message::findById($message['messageId'] + 1));
    }

    public function testSendClientIdIsPerSenderAndCaseSensitive(): void
    {
        $mine  = $this->send($this->client, 'Hello', 'same');
        $bobs  = $this->send($this->bobClient, 'Hello', 'same');
        $upper = $this->send($this->client, 'Hello', 'SAME');

        $this->assertSame(3, count(array_unique([$mine['messageId'], $bobs['messageId'], $upper['messageId']])));
    }

    public function testSendClientIdTakenInOtherConversation(): void
    {
        $this->send($this->client, 'Hello', 'taken');
        [, $carol] = $this->signup('carol');
        $otherId = Conversation::findOrCreateSingle($this->me, $carol['userId'])['conversationId'];

        $res = $this->client->post('/messages/send', ['conversationId' => $otherId, 'text' => 'Hello', 'clientId' => 'taken']);

        $this->assertError(ErrorCode::MESSAGE_CLIENT_ID_TAKEN, $res);
        $this->assertSame([], Message::findList($otherId, null, 1)['messages']);
    }

    public function testSendConversationNotFound(): void
    {
        [$carolClient] = $this->signup('carol');

        foreach (['', 'abc', '-1', '999', (string) $this->conversationId] as $conversationId) {
            $res = $carolClient->post('/messages/send', ['conversationId' => $conversationId, 'text' => 'Hi', 'clientId' => 'c1']);
            $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $res);
        }
        $this->assertNull(Message::findById(1));
    }

    public function testSendNotAuthenticated(): void
    {
        $res = $this->client()->post('/messages/send', ['conversationId' => $this->conversationId, 'text' => 'Hi', 'clientId' => 'c1']);

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $res);
    }

    // ---- /messages/edit ----

    public function testEdit(): void
    {
        $id = $this->send($this->client, 'Hello')['messageId'];

        $res = $this->client->post('/messages/edit', ['messageId' => $id, 'newText' => ' Bye ']);

        $this->assertSuccess($res);
        $message = $res['response']['message'];
        $this->assertSame(Message::findById($id), $message);
        $this->assertSame('Bye', $message['text']);
        $this->assertTrue($message['isEdited']);
        // Отредактировано последнее сообщение — lastMessage и updatedInfoAt переписки обновлены
        $this->assertSame(Message::toLastMessage($message), $res['response']['lastMessage']);
        $conversation = Conversation::findById($this->conversationId, $this->me);
        $this->assertSame($res['response']['lastMessage'], $conversation['lastMessage']);
        $this->assertSame($message['updatedAt'], $conversation['updatedInfoAt']);
        $this->assertSame($message['createdAt'], $conversation['positionUpdatedAt']);
        $this->assertUsersAction([$this->me], $res);
    }

    public function testEditNotLastMessage(): void
    {
        $id = $this->send($this->client, 'Hello')['messageId'];
        $this->send($this->bobClient, 'Hi');
        $conversation = Conversation::findById($this->conversationId, $this->me);

        $res = $this->client->post('/messages/edit', ['messageId' => $id, 'newText' => 'Bye']);

        $this->assertSuccess($res);
        $this->assertSame(['message'], array_keys($res['response']));
        $this->assertSame($conversation, Conversation::findById($this->conversationId, $this->me));
    }

    public function testEditSameTextKeepsMessage(): void
    {
        $message = $this->send($this->client, 'Hello');

        $res = $this->client->post('/messages/edit', ['messageId' => $message['messageId'], 'newText' => ' Hello ']);

        $this->assertSuccess($res);
        $this->assertSame(['message' => $message], $res['response']);
        $this->assertFalse(Message::findById($message['messageId'])['isEdited']);
        $this->assertUsersAction([$this->me], $res);
    }

    public function testEditOthersMessageForbidden(): void
    {
        $id = $this->send($this->bobClient, 'Hello')['messageId'];

        $res = $this->client->post('/messages/edit', ['messageId' => $id, 'newText' => 'Hacked']);

        $this->assertError(ErrorCode::MESSAGE_EDIT_FORBIDDEN, $res);
        $this->assertSame('Hello', Message::findById($id)['text']);
    }

    public function testEditMessageNotFound(): void
    {
        $id = $this->send($this->client, 'Hello')['messageId'];
        [$carolClient] = $this->signup('carol');

        foreach (['', 'abc', '-1', '999'] as $messageId) {
            $this->assertError(ErrorCode::MESSAGE_NOT_FOUND, $this->client->post('/messages/edit', ['messageId' => $messageId, 'newText' => 'x']));
        }
        // Сообщение чужой переписки
        $this->assertError(ErrorCode::MESSAGE_NOT_FOUND, $carolClient->post('/messages/edit', ['messageId' => $id, 'newText' => 'x']));
    }

    public function testEditInvalidText(): void
    {
        $id = $this->send($this->client, 'Hello')['messageId'];

        foreach ([[], ['newText' => ' '], ['newText' => str_repeat('a', Message::TEXT_MAX_LENGTH + 1)]] as $params) {
            $this->assertError(ErrorCode::INVALID_TEXT, $this->client->post('/messages/edit', ['messageId' => $id] + $params));
        }
        $this->assertFalse(Message::findById($id)['isEdited']);
    }

    public function testEditNotAuthenticated(): void
    {
        $id = $this->send($this->client, 'Hello')['messageId'];

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->post('/messages/edit', ['messageId' => $id, 'newText' => 'x']));
    }

    // ---- /messages/getList ----

    public function testGetListEmpty(): void
    {
        $res = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId]);

        $this->assertSuccess($res);
        $this->assertSame(['messages' => [], 'nextBlockId' => null, 'hasNext' => false], $res['response']);
        $this->assertFalse(array_key_exists('actions', $res));
    }

    public function testGetListUsersAction(): void
    {
        $this->send($this->client, 'Hi');
        $this->send($this->bobClient, 'Hello');
        $this->send($this->client, 'How are you?');

        $res = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId]);

        $this->assertSuccess($res);
        $this->assertUsersAction([$this->me, $this->bob], $res);
    }

    public function testGetListPagination(): void
    {
        // Блоки: 20, 20, 20, 5 (самый новый)
        $ids = $this->sendMany(3 * Message::BLOCK_SIZE + 5);

        $page1 = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId]);
        $this->assertSuccess($page1);
        $this->assertSame(array_reverse(array_slice($ids, Message::BLOCK_SIZE)), array_column($page1['response']['messages'], 'messageId'));
        $this->assertTrue($page1['response']['hasNext']);
        $this->assertSame(Message::findById($ids[0])['blockId'], $page1['response']['nextBlockId']);

        $page2 = $this->bobClient->post('/messages/getList', [
            'conversationId' => $this->conversationId,
            'blockId'        => $page1['response']['nextBlockId'],
        ]);
        $this->assertSame(array_reverse(array_slice($ids, 0, Message::BLOCK_SIZE)), array_column($page2['response']['messages'], 'messageId'));
        $this->assertFalse($page2['response']['hasNext']);
        $this->assertNull($page2['response']['nextBlockId']);
    }

    public function testGetListConversationNotFound(): void
    {
        [$carolClient] = $this->signup('carol');

        foreach (['', 'abc', '999'] as $conversationId) {
            $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->client->get('/messages/getList', ['conversationId' => $conversationId]));
        }
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $carolClient->get('/messages/getList', ['conversationId' => $this->conversationId]));
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->client->get('/messages/getList', ['conversationId' => [$this->conversationId]]));
    }

    public function testGetListBlockNotFound(): void
    {
        $carol = $this->insertUser('carol');
        $other = Conversation::findOrCreateSingle($this->me, $carol)['conversationId'];
        $otherBlock = Message::send($other, $carol, 'Hi')['blockId'];

        foreach (['abc', '-1', '999', (string) $otherBlock] as $blockId) {
            $res = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId, 'blockId' => $blockId]);
            $this->assertError(ErrorCode::MESSAGE_BLOCK_NOT_FOUND, $res);
        }
    }

    public function testGetListNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->get('/messages/getList', ['conversationId' => $this->conversationId]));
    }

    public function testGetListReceivedAfter(): void
    {
        $old = $this->send($this->bobClient, 'old');
        $edited = $this->send($this->client, 'edited');
        $this->setCreatedAt([$old['messageId'], $edited['messageId']], 1000);
        $new = $this->send($this->bobClient, 'new');
        $this->assertSuccess($this->client->post('/messages/edit', ['messageId' => $edited['messageId'], 'newText' => 'changed']));

        $res = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId, 'receivedAfter' => '1000']);

        $this->assertSuccess($res);
        $this->assertSame([
            'messages'        => [Message::findById($new['messageId'])],
            'nextBlockId'     => null,
            'hasNext'         => false,
            'updatedBlockIds' => [$edited['blockId']],
        ], $res['response']);
        $this->assertUsersAction([$this->bob], $res);
    }

    public function testGetListReceivedAfterNothingNew(): void
    {
        $message = $this->send($this->client, 'Hi');

        $res = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId, 'receivedAfter' => $message['createdAt']]);

        $this->assertSame(['messages' => [], 'nextBlockId' => null, 'hasNext' => false, 'updatedBlockIds' => []], $res['response']);
        $this->assertFalse(array_key_exists('actions', $res));
    }

    public function testGetListReceivedAfterBeyondPage(): void
    {
        // Блоки: 20, 20, 20, 5 — все новые, страница — три новых блока
        $ids = $this->sendMany(3 * Message::BLOCK_SIZE + 5);

        $res = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId, 'receivedAfter' => '0']);

        $this->assertSuccess($res);
        $this->assertSame($this->client->get('/messages/getList', ['conversationId' => $this->conversationId])['response'], $res['response']);
        $this->assertTrue($res['response']['hasNext']);
        $this->assertSame(Message::findById($ids[0])['blockId'], $res['response']['nextBlockId']);
    }

    public function testGetListReceivedAfterInvalid(): void
    {
        $blockId = $this->send($this->client, 'Hi')['blockId'];

        foreach (['-1', '1.5', 'abc', ['1']] as $value) {
            $res = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId, 'receivedAfter' => $value]);
            $this->assertError(ErrorCode::INVALID_PARAMS, $res);
        }
        $res = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId, 'receivedAfter' => '0', 'blockId' => $blockId]);
        $this->assertError(ErrorCode::INVALID_PARAMS, $res);

        // Доступ к переписке проверяется раньше параметров
        [$carolClient] = $this->signup('carol');
        $res = $carolClient->get('/messages/getList', ['conversationId' => $this->conversationId, 'receivedAfter' => 'abc']);
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $res);
    }

    public function testGetListReceivedAfterUpdatedBlocksLimit(): void
    {
        // Изменены старые сообщения в BLOCK_BATCH_MAX_COUNT + 1 блоках — отдаются только самые новые
        $ids = $this->sendMany((Message::BLOCK_BATCH_MAX_COUNT + 1) * Message::BLOCK_SIZE);
        $this->setCreatedAt($ids, 1000);
        $blockIds = [];
        foreach (array_chunk($ids, Message::BLOCK_SIZE) as $blockMessageIds) {
            $blockIds[] = Message::edit($blockMessageIds[0], 'edited')['blockId'];
        }

        $res = $this->client->get('/messages/getList', ['conversationId' => $this->conversationId, 'receivedAfter' => '1000']);

        $this->assertSuccess($res);
        $this->assertSame([
            'messages'        => [],
            'nextBlockId'     => null,
            'hasNext'         => false,
            'updatedBlockIds' => array_slice(array_reverse($blockIds), 0, Message::BLOCK_BATCH_MAX_COUNT),
        ], $res['response']);
    }

    // ---- /messages/getBlock ----

    public function testGetBlock(): void
    {
        $ids = $this->sendMany(Message::BLOCK_SIZE + 1);
        $blockId = Message::findById($ids[0])['blockId'];

        $res = $this->bobClient->get('/messages/getBlock', ['blockId' => $blockId]);

        $this->assertSuccess($res);
        $this->assertSame(array_reverse(array_slice($ids, 0, Message::BLOCK_SIZE)), array_column($res['response']['messages'], 'messageId'));
        $this->assertSame(Message::findById($ids[0]), end($res['response']['messages']));
        $this->assertUsersAction([$this->me, $this->bob], $res);
    }

    public function testGetBlockNotFound(): void
    {
        $blockId = $this->send($this->client, 'Hi')['blockId'];
        [$carolClient] = $this->signup('carol');

        foreach (['', 'abc', '999'] as $id) {
            $this->assertError(ErrorCode::MESSAGE_BLOCK_NOT_FOUND, $this->client->get('/messages/getBlock', ['blockId' => $id]));
        }
        $this->assertError(ErrorCode::MESSAGE_BLOCK_NOT_FOUND, $carolClient->get('/messages/getBlock', ['blockId' => $blockId]));
    }

    public function testGetBlockNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->get('/messages/getBlock', ['blockId' => 1]));
    }

    // ---- /messages/getBlockBatch ----

    public function testGetBlockBatch(): void
    {
        $ids = $this->sendMany(2 * Message::BLOCK_SIZE + 1);
        [$first, $second, $third] = array_map(fn($i) => Message::findById($ids[$i])['blockId'], [0, Message::BLOCK_SIZE, 2 * Message::BLOCK_SIZE]);
        $edited = $this->client->post('/messages/edit', ['messageId' => $ids[0], 'newText' => 'changed'])['response']['message'];
        // Все сообщения блока удалены — блок остаётся пустым
        DB::get()->prepare('DELETE FROM messages WHERE block_id = ?')->execute([$second]);
        $carol = $this->insertUser('carol');
        $foreign = Message::send(Conversation::findOrCreateSingle($this->bob, $carol)['conversationId'], $carol, 'Hi')['blockId'];

        $res = $this->client->get('/messages/getBlockBatch', ['blockIds' => "$third,999,$first,$foreign,$second,$third"]);

        $this->assertSuccess($res);
        $this->assertSame([
            'blocks' => [
                ['blockId' => $third, 'conversationId' => $this->conversationId, 'messages' => [Message::findById($ids[2 * Message::BLOCK_SIZE])], 'updatedAt' => null],
                [
                    'blockId'        => $first,
                    'conversationId' => $this->conversationId,
                    'messages'       => array_map([Message::class, 'findById'], array_reverse(array_slice($ids, 0, Message::BLOCK_SIZE))),
                    'updatedAt'      => $edited['updatedAt'],
                ],
                ['blockId' => $second, 'conversationId' => $this->conversationId, 'messages' => [], 'updatedAt' => null],
            ],
            'notFoundIds' => [999, $foreign],
        ], $res['response']);
        $this->assertUsersAction([$this->me, $this->bob], $res);
    }

    public function testGetBlockBatchArrayParam(): void
    {
        $blockId = $this->send($this->client, 'Hi')['blockId'];

        $res = $this->client->get('/messages/getBlockBatch', ['blockIds' => [(string) $blockId]]);

        $this->assertSame([$blockId], array_column($res['response']['blocks'], 'blockId'));
    }

    public function testGetBlockBatchNothingFound(): void
    {
        $res = $this->client->get('/messages/getBlockBatch', ['blockIds' => '5']);

        $this->assertSame(['blocks' => [], 'notFoundIds' => [5]], $res['response']);
        $this->assertFalse(array_key_exists('actions', $res));
    }

    public function testGetBlockBatchInvalidParams(): void
    {
        $tooMany = implode(',', range(1, Message::BLOCK_BATCH_MAX_COUNT + 1));
        foreach ([[], ['blockIds' => ''], ['blockIds' => '1,x'], ['blockIds' => '-1'], ['blockIds' => $tooMany]] as $params) {
            $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->get('/messages/getBlockBatch', $params));
        }
        $this->assertSuccess($this->client->get('/messages/getBlockBatch', ['blockIds' => implode(',', range(1, Message::BLOCK_BATCH_MAX_COUNT))]));
    }

    public function testGetBlockBatchNotAuthenticated(): void
    {
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->get('/messages/getBlockBatch', ['blockIds' => '1']));
    }

    // ---- Хелперы ----

    /** Ставит сообщениям createdAt (и updatedAt) = $ms. */
    private function setCreatedAt(array $ids, int $ms): void
    {
        $stmt = DB::get()->prepare('UPDATE messages SET created_at = ' . DB::fromMs() . ', updated_at = created_at WHERE id = ?');
        foreach ($ids as $id) {
            $stmt->execute([$ms, $id]);
        }
    }

    private function send(ApiClient $client, string $text, ?string $clientId = null): array
    {
        $res = $client->post('/messages/send', [
            'conversationId' => $this->conversationId, 'text' => $text, 'clientId' => $clientId ?? bin2hex(random_bytes(8)),
        ]);
        $this->assertSuccess($res);

        return $res['response']['message'];
    }

    /** Быстрая отправка напрямую через сущность, минуя HTTP */
    private function sendMany(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Message::send($this->conversationId, $i % 2 ? $this->bob : $this->me, "m$i")['messageId'];
        }
        return $ids;
    }
}
