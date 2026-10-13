<?php

require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

class MessageTest extends TestCase
{
    public function testFromRow(): void
    {
        $message = Message::fromRow([
            'id' => '7', 'conversation_id' => '2', 'block_id' => '3', 'sender_id' => '5', 'client_id' => 'local-7',
            'type' => 'text', 'text' => 'Hi', 'is_edited' => '1',
            'created_at' => '1790000000123', 'updated_at' => '1790000060456',
        ]);

        $this->assertSame([
            'messageId'      => 7,
            'clientId'       => 'local-7',
            'conversationId' => 2,
            'blockId'        => 3,
            'senderId'       => 5,
            'type'           => 'text',
            'text'           => 'Hi',
            'isEdited'       => true,
            'createdAt'      => 1790000000123,
            'updatedAt'      => 1790000060456,
        ], $message);
    }

    public function testSend(): void
    {
        [$conversationId, $a] = $this->conversation();

        $before  = (int) floor(microtime(true) * 1000);
        $message = Message::send($conversationId, $a, 'Hello');

        $this->assertSame(1, $message['messageId']);
        $this->assertSame($conversationId, $message['conversationId']);
        $this->assertSame($a, $message['senderId']);
        $this->assertSame('text', $message['type']);
        $this->assertSame('Hello', $message['text']);
        $this->assertFalse($message['isEdited']);
        $this->assertTrue(abs($message['createdAt'] - $before) <= 2000, 'createdAt must be current time in milliseconds');
        $this->assertSame($message['createdAt'], $message['updatedAt']);
        $this->assertTrue(
            (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $message['clientId']),
            'Without clientId a UUID v4 is generated'
        );
    }

    public function testSendWithClientIdIsIdempotent(): void
    {
        [$conversationId, $a, $b] = $this->conversation();

        $first = Message::send($conversationId, $a, 'Hello', 'local-1', $isNew);
        $this->assertTrue($isNew);
        $this->assertSame('local-1', $first['clientId']);

        $retry = Message::send($conversationId, $a, 'Hello again', 'local-1', $isNew);
        $this->assertFalse($isNew);
        $this->assertSame($first, $retry);
        $this->assertSame([1], array_map('intval', DB::get()->query('SELECT messages_count FROM message_blocks')->fetchAll(PDO::FETCH_COLUMN)));

        // У другого отправителя тот же clientId — другое сообщение
        $this->assertFalse($first['messageId'] === Message::send($conversationId, $b, 'Hi', 'local-1', $isNew)['messageId']);
        $this->assertTrue($isNew);
    }

    public function testNormalizeClientId(): void
    {
        $this->assertSame('6f1c2a9e-4b7d-4e3a-9c51-0d2f8b6a7e14', Message::normalizeClientId('6f1c2a9e-4b7d-4e3a-9c51-0d2f8b6a7e14'));
        $this->assertSame('A_b-1', Message::normalizeClientId('A_b-1'));
        foreach (['', ' a', 'a\n', "a\n", 'a.b', 'ид', str_repeat('a', Message::CLIENT_ID_MAX_LENGTH + 1), null, 1, ['a']] as $value) {
            $this->assertNull(Message::normalizeClientId($value));
        }
    }

    public function testSendFillsBlocks(): void
    {
        [$conversationId, $a] = $this->conversation();
        [$otherId, $b] = $this->conversation('c', 'd');

        $blockIds = [];
        for ($i = 0; $i < Message::BLOCK_SIZE + 1; $i++) {
            $blockIds[] = Message::send($conversationId, $a, "m$i")['blockId'];
        }
        $otherBlock = Message::send($otherId, $b, 'other')['blockId'];

        $this->assertSame(array_fill(0, Message::BLOCK_SIZE, $blockIds[0]), array_slice($blockIds, 0, Message::BLOCK_SIZE));
        $this->assertTrue($blockIds[Message::BLOCK_SIZE] !== $blockIds[0], 'Message after a full block goes to a new block');
        $this->assertTrue(!in_array($otherBlock, $blockIds, true), 'Each conversation has its own blocks');

        $counts = DB::get()->query('SELECT messages_count FROM message_blocks ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([Message::BLOCK_SIZE, 1, 1], array_map('intval', $counts));
    }

    public function testEdit(): void
    {
        [$conversationId, $a] = $this->conversation();
        $id = Message::send($conversationId, $a, 'Hello')['messageId'];

        $message = Message::edit($id, 'Bye');

        $this->assertSame('Bye', $message['text']);
        $this->assertTrue($message['isEdited']);
        $this->assertSame('Bye', Message::findById($id)['text']);
        $this->assertTrue($message['updatedAt'] >= $message['createdAt']);
    }

    public function testToLastMessage(): void
    {
        [$conversationId, $a] = $this->conversation();
        $message = Message::send($conversationId, $a, 'Hello');

        $this->assertSame(
            ['messageId' => $message['messageId'], 'type' => 'text', 'text' => 'Hello', 'updatedAt' => $message['updatedAt']],
            Message::toLastMessage($message)
        );
    }

    public function testFindByIdMissing(): void
    {
        $this->assertNull(Message::findById(42));
    }

    public function testFindListEmpty(): void
    {
        [$conversationId] = $this->conversation();

        $this->assertSame(['messages' => [], 'nextBlockId' => null], Message::findList($conversationId, null, 40));
    }

    public function testFindListTakesWholeBlocksUntilMinCount(): void
    {
        [$conversationId, $a] = $this->conversation();
        // Блоки: 20, 20, 20, 5 (самый новый)
        $ids = $this->sendMany($conversationId, $a, 3 * Message::BLOCK_SIZE + 5);
        $blocks = $this->blockIds();

        // 5 + 20 = 25 < 40, поэтому берётся ещё один блок: 45
        $list = Message::findList($conversationId, null, 40);
        $this->assertSame(array_reverse(array_slice($ids, Message::BLOCK_SIZE)), array_column($list['messages'], 'messageId'));
        $this->assertSame($blocks[0], $list['nextBlockId']);

        $rest = Message::findList($conversationId, $list['nextBlockId'], 40);
        $this->assertSame(array_reverse(array_slice($ids, 0, Message::BLOCK_SIZE)), array_column($rest['messages'], 'messageId'));
        $this->assertNull($rest['nextBlockId']);
    }

    public function testFindListStopsExactlyAtMinCount(): void
    {
        [$conversationId, $a] = $this->conversation();
        $this->sendMany($conversationId, $a, 3 * Message::BLOCK_SIZE);
        $blocks = $this->blockIds();

        $list = Message::findList($conversationId, null, 2 * Message::BLOCK_SIZE);

        $this->assertCount(2 * Message::BLOCK_SIZE, $list['messages']);
        $this->assertSame($blocks[0], $list['nextBlockId']);
    }

    public function testFindListWithAlmostEmptyNewestBlock(): void
    {
        [$conversationId, $a] = $this->conversation();
        // Блоки: 20, 20, 20, 1 (самый новый) — для 40 сообщений нужно 4 блока: 1 + 20 + 20 и следующий
        $ids = $this->sendMany($conversationId, $a, 3 * Message::BLOCK_SIZE + 1);
        $blocks = $this->blockIds();

        $list = Message::findList($conversationId, null, 40);

        $this->assertSame(array_reverse(array_slice($ids, Message::BLOCK_SIZE)), array_column($list['messages'], 'messageId'));
        $this->assertSame($blocks[0], $list['nextBlockId']);
    }

    public function testFindListIgnoresOtherConversations(): void
    {
        [$conversationId, $a] = $this->conversation();
        [$otherId, $b] = $this->conversation('c', 'd');
        $mine = Message::send($conversationId, $a, 'mine')['messageId'];
        Message::send($otherId, $b, 'theirs');

        $list = Message::findList($conversationId, null, 40);

        $this->assertSame([$mine], array_column($list['messages'], 'messageId'));
        $this->assertNull($list['nextBlockId']);
    }

    public function testFindBlockConversationId(): void
    {
        [$conversationId, $a] = $this->conversation();
        $blockId = Message::send($conversationId, $a, 'Hi')['blockId'];

        $this->assertSame($conversationId, Message::findBlockConversationId($blockId));
        $this->assertNull(Message::findBlockConversationId($blockId + 1));
    }

    public function testNormalizeText(): void
    {
        $this->assertSame('Hi', Message::normalizeText("  Hi\n"));
        $this->assertSame("a?b", Message::normalizeText("a\xFFb"));
        $this->assertSame('Hi', Message::normalizeText("\u{00A0}\u{200B}\u{FEFF}Hi\u{3000}\u{2060}"));
        // Теги в конце флага региона — часть эмодзи, не обрезаются
        $flag = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";
        $this->assertSame("Hi $flag", Message::normalizeText("Hi $flag "));
        $this->assertSame(str_repeat('я', Message::TEXT_MAX_LENGTH), Message::normalizeText(str_repeat('я', Message::TEXT_MAX_LENGTH)));

        foreach (['', "  \n ", "\u{00A0}\u{200B}\u{200D}", str_repeat('a', Message::TEXT_MAX_LENGTH + 1), ['a'], null] as $text) {
            $this->assertNull(Message::normalizeText($text));
        }
    }

    /** Личная переписка двух новых пользователей: [conversationId, id первого, id второго] */
    private function conversation(string $loginA = 'a', string $loginB = 'b'): array
    {
        $a = $this->insertUser($loginA);
        $b = $this->insertUser($loginB);

        return [Conversation::findOrCreateSingle($a, $b)['conversationId'], $a, $b];
    }

    private function sendMany(int $conversationId, int $senderId, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Message::send($conversationId, $senderId, "m$i")['messageId'];
        }
        return $ids;
    }

    private function blockIds(): array
    {
        return array_map('intval', DB::get()->query('SELECT id FROM message_blocks ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }
}
