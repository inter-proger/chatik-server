<?php

require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

class MessageTest extends TestCase
{
    /** Начало отсчёта createdAt в sendTimed() — заведомо в прошлом, правки получат время позже */
    const T0 = 1700000000000;

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

    public function testEditSetsBlockUpdatedAt(): void
    {
        [$conversationId, $a] = $this->conversation();
        $ids = $this->sendMany($conversationId, $a, Message::BLOCK_SIZE + 1);
        $blocks = $this->blockIds();
        $this->assertSame([null, null], $this->blockUpdatedAts());

        $message = Message::edit($ids[1], 'edited');

        $this->assertSame([$message['updatedAt'], null], $this->blockUpdatedAts());
        $this->assertSame($message['updatedAt'], Message::findBlocksByIds([$blocks[0]], $a)[$blocks[0]]['updatedAt']);
    }

    public function testToLastMessage(): void
    {
        [$conversationId, $a] = $this->conversation();
        $message = Message::send($conversationId, $a, 'Hello');

        $this->assertSame(
            ['messageId' => $message['messageId'], 'senderId' => $a, 'type' => 'text', 'text' => 'Hello', 'updatedAt' => $message['updatedAt']],
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

    public function testFindBlocksByIds(): void
    {
        [$conversationId, $a, $b] = $this->conversation();
        [$otherId, $c] = $this->conversation('c', 'd');
        $ids = $this->sendMany($conversationId, $a, Message::BLOCK_SIZE + 2);
        [$first, $second] = $this->blockIds();
        $foreign = Message::send($otherId, $c, 'theirs')['blockId'];
        $edited = Message::edit($ids[0], 'edited');

        $blocks = Message::findBlocksByIds([$second, 999, $first, $foreign], $b);

        $keys = array_keys($blocks);
        sort($keys);
        $this->assertSame([$first, $second], $keys, 'Missing and foreign blocks are skipped');
        $this->assertSame([
            'blockId'        => $first,
            'conversationId' => $conversationId,
            'messages'       => array_map([Message::class, 'findById'], array_reverse(array_slice($ids, 0, Message::BLOCK_SIZE))),
            'updatedAt'      => $edited['updatedAt'],
        ], $blocks[$first]);
        $this->assertSame(array_reverse(array_slice($ids, Message::BLOCK_SIZE)), array_column($blocks[$second]['messages'], 'messageId'));
        $this->assertNull($blocks[$second]['updatedAt']);
        $this->assertSame([], Message::findBlocksByIds([], $a));
    }

    public function testFindBlocksByIdsEmptyBlock(): void
    {
        [$conversationId, $a] = $this->conversation();
        $blockId = Message::send($conversationId, $a, 'Hi')['blockId'];
        DB::get()->prepare('DELETE FROM messages WHERE block_id = ?')->execute([$blockId]);

        $this->assertSame([], Message::findBlocksByIds([$blockId], $a)[$blockId]['messages']);
    }

    public function testFindListReceivedAfterNothingNew(): void
    {
        [$conversationId, $a] = $this->conversation();
        $this->sendTimed($conversationId, $a, 3 * Message::BLOCK_SIZE + 5);

        $this->assertSame(
            ['messages' => [], 'nextBlockId' => null, 'hasNext' => false, 'updatedBlockIds' => []],
            Message::findListReceivedAfter($conversationId, self::T0 + 1000 * 64, 40)
        );
    }

    public function testFindListReceivedAfterNewWithinPage(): void
    {
        [$conversationId, $a] = $this->conversation();
        $ids = $this->sendTimed($conversationId, $a, 3 * Message::BLOCK_SIZE + 5);

        // Новые — 10 последних (createdAt строго больше отметки)
        $list = Message::findListReceivedAfter($conversationId, self::T0 + 1000 * 54, 40);

        $this->assertSame(array_reverse(array_slice($ids, 55)), array_column($list['messages'], 'messageId'));
        $this->assertSame(Message::findById($ids[64]), $list['messages'][0]);
        $this->assertFalse($list['hasNext']);
        $this->assertNull($list['nextBlockId']);
        $this->assertSame([], $list['updatedBlockIds']);
    }

    public function testFindListReceivedAfterNewBeyondPage(): void
    {
        [$conversationId, $a] = $this->conversation();
        // Блоки: 20, 20, 20, 5 — страница из трёх новых блоков (45 сообщений), новых 50
        $ids = $this->sendTimed($conversationId, $a, 3 * Message::BLOCK_SIZE + 5);
        $blocks = $this->blockIds();

        $list = Message::findListReceivedAfter($conversationId, self::T0 + 1000 * 14, 40);

        // Страница совпадает с первой страницей без фильтра
        $this->assertSame(Message::findList($conversationId, null, 40) + ['hasNext' => true], $list);
        $this->assertSame(array_reverse(array_slice($ids, Message::BLOCK_SIZE)), array_column($list['messages'], 'messageId'));
        $this->assertSame($blocks[0], $list['nextBlockId']);
    }

    public function testFindListReceivedAfterBoundaryAtPageEdge(): void
    {
        [$conversationId, $a] = $this->conversation();
        // Новые — ровно страница (45): следующий блок целиком старый, кэш остаётся валидным
        $ids = $this->sendTimed($conversationId, $a, 3 * Message::BLOCK_SIZE + 5);

        $list = Message::findListReceivedAfter($conversationId, self::T0 + 1000 * 19, 40);

        $this->assertSame(array_reverse(array_slice($ids, Message::BLOCK_SIZE)), array_column($list['messages'], 'messageId'));
        $this->assertFalse($list['hasNext']);
        $this->assertNull($list['nextBlockId']);
        $this->assertSame([], $list['updatedBlockIds']);
    }

    public function testFindListReceivedAfterSkipsEmptyBlocks(): void
    {
        [$conversationId, $a] = $this->conversation();
        // Блоки: 20, 20, 20, 5; второй и третий опустели — на странице 5 сообщений, за ней — первый блок
        $ids = $this->sendTimed($conversationId, $a, 3 * Message::BLOCK_SIZE + 5);
        $blocks = $this->blockIds();
        DB::get()->prepare('DELETE FROM messages WHERE block_id IN (?, ?)')->execute([$blocks[1], $blocks[2]]);

        $this->assertSame(
            array_reverse(array_slice($ids, 3 * Message::BLOCK_SIZE)),
            array_column(Message::findList($conversationId, null, 40)['messages'], 'messageId')
        );

        // Новое сообщение есть в первом блоке — за пустыми блоками
        $list = Message::findListReceivedAfter($conversationId, self::T0 + 1000 * 18, 40);
        $this->assertTrue($list['hasNext']);
        $this->assertSame($blocks[0], $list['nextBlockId']);

        // Первый блок целиком старый
        $list = Message::findListReceivedAfter($conversationId, self::T0 + 1000 * 19, 40);
        $this->assertFalse($list['hasNext']);
        $this->assertCount(5, $list['messages']);
    }

    public function testFindListReceivedAfterAllEmptyPage(): void
    {
        [$conversationId, $a] = $this->conversation();
        $this->sendTimed($conversationId, $a, 3 * Message::BLOCK_SIZE + 5);
        $blocks = $this->blockIds();
        DB::get()->prepare('DELETE FROM messages WHERE block_id IN (?, ?, ?)')->execute([$blocks[1], $blocks[2], $blocks[3]]);

        $list = Message::findListReceivedAfter($conversationId, self::T0, 40);

        $this->assertSame(['messages' => [], 'nextBlockId' => $blocks[0], 'hasNext' => true], $list);
    }

    public function testFindListReceivedAfterUpdatedBlocks(): void
    {
        [$conversationId, $a] = $this->conversation();
        [$otherId, $c] = $this->conversation('c', 'd');
        $ids = $this->sendTimed($conversationId, $a, 3 * Message::BLOCK_SIZE + 5);
        $blocks = $this->blockIds();
        Message::edit($ids[0], 'edited');
        Message::edit($ids[50], 'edited');
        // Правка нового сообщения тоже отмечает блок
        Message::edit($ids[64], 'edited');
        Message::edit(Message::send($otherId, $c, 'theirs')['messageId'], 'edited');

        $list = Message::findListReceivedAfter($conversationId, self::T0 + 1000 * 60, 40);

        $this->assertSame([$ids[64], $ids[63], $ids[62], $ids[61]], array_column($list['messages'], 'messageId'));
        $this->assertSame('edited', $list['messages'][0]['text']);
        $this->assertSame([$blocks[3], $blocks[2], $blocks[0]], $list['updatedBlockIds']);

        // Отметка после правок — блоков нет
        $after = Message::findById($ids[64])['updatedAt'];
        $this->assertSame([], Message::findListReceivedAfter($conversationId, $after, 40)['updatedBlockIds']);
    }

    public function testFindListReceivedAfterUpdatedBlocksLimit(): void
    {
        [$conversationId, $a] = $this->conversation();
        $count = (Message::BLOCK_BATCH_MAX_COUNT + 2) * Message::BLOCK_SIZE;
        $ids = $this->sendTimed($conversationId, $a, $count);
        for ($i = 0; $i < $count; $i += Message::BLOCK_SIZE) {
            Message::edit($ids[$i], 'edited');
        }

        $list = Message::findListReceivedAfter($conversationId, self::T0 + 1000 * $count, 40);

        $this->assertSame(array_slice(array_reverse($this->blockIds()), 0, Message::BLOCK_BATCH_MAX_COUNT), $list['updatedBlockIds']);
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

    /** Отправляет $count сообщений с createdAt = T0 + i секунд (i — номер сообщения); их id. */
    private function sendTimed(int $conversationId, int $senderId, int $count): array
    {
        $ids = $this->sendMany($conversationId, $senderId, $count);
        $stmt = DB::get()->prepare('UPDATE messages SET created_at = ' . DB::fromMs() . ', updated_at = created_at WHERE id = ?');
        foreach ($ids as $i => $id) {
            $stmt->execute([self::T0 + 1000 * $i, $id]);
        }
        return $ids;
    }

    /** updatedAt блоков (мс или null) по возрастанию id */
    private function blockUpdatedAts(): array
    {
        $rows = DB::get()->query('SELECT ' . DB::ms('updated_at') . ' FROM message_blocks ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        return array_map(fn($v) => $v !== null ? (int) $v : null, $rows);
    }

    private function blockIds(): array
    {
        return array_map('intval', DB::get()->query('SELECT id FROM message_blocks ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }
}
