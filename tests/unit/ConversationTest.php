<?php

require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

class ConversationTest extends TestCase
{
    public function testFromRow(): void
    {
        $conversation = Conversation::fromRow(
            ['id' => '3', 'type' => 'group', 'name' => 'Team', 'position_updated_at' => '1790846400123',
             'updated_info_at' => '1790846000456', 'f_id' => null, 'lm_id' => null],
            [1, 2],
            null
        );

        $this->assertSame([
            'conversationId'    => 3,
            'type'              => 'group',
            'memberIds'         => [1, 2],
            'name'              => 'Team',
            'avatar'            => null,
            'opponentUserId'    => null,
            'lastMessage'       => null,
            'positionUpdatedAt' => 1790846400123,
            'updatedInfoAt'     => 1790846000456,
        ], $conversation);
    }

    public function testFromRowWithLastMessage(): void
    {
        $conversation = Conversation::fromRow([
            'id' => 3, 'type' => 'group', 'name' => 'Team', 'position_updated_at' => 1, 'updated_info_at' => 1,
            'f_id' => null, 'lm_id' => '15', 'lm_type' => 'text', 'lm_text' => 'Hi', 'lm_updated_at' => '1790846400123',
        ], [], null);

        $this->assertSame(
            ['messageId' => 15, 'type' => 'text', 'text' => 'Hi', 'updatedAt' => 1790846400123],
            $conversation['lastMessage']
        );
    }

    public function testFromRowWithAvatar(): void
    {
        $conversation = Conversation::fromRow([
            'id' => 3, 'type' => 'group', 'name' => 'Team', 'position_updated_at' => 1, 'updated_info_at' => 1,
            'lm_id' => null, 'f_id' => 4, 'f_type' => 'image', 'f_path' => 'static/uploads/g.png', 'f_size' => 1,
            'f_filename' => 'g.png', 'f_extension' => 'png', 'f_data' => '{}',
        ], [], null);

        $this->assertSame(4, $conversation['avatar']['fileId']);
    }

    public function testFindOrCreateSingleCreates(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');

        $conversation = Conversation::findOrCreateSingle($b, $a, $created);

        $this->assertTrue($created);
        $this->assertSame('single', $conversation['type']);
        $this->assertSame([$a, $b], $conversation['memberIds']);
        $this->assertSame($a, $conversation['opponentUserId']);
        $this->assertNull($conversation['name']);

        $key = DB::get()->query('SELECT single_key FROM conversations')->fetchColumn();
        $this->assertSame("{$a}_{$b}", $key);
    }

    public function testFindOrCreateSingleReturnsExistingInAnyOrder(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');

        $first  = Conversation::findOrCreateSingle($a, $b);
        $second = Conversation::findOrCreateSingle($b, $a, $created);

        $this->assertFalse($created);
        $this->assertSame($first['conversationId'], $second['conversationId']);
        $this->assertSame($b, $first['opponentUserId']);
        $this->assertSame($a, $second['opponentUserId']);
        $this->assertSame('1', (string) DB::get()->query('SELECT COUNT(*) FROM conversations')->fetchColumn());
    }

    public function testTimesFromDatabaseInMilliseconds(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $id = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        // Время хранится в UTC: строка без часового пояса — это UTC
        DB::get()->exec(
            "UPDATE conversations SET updated_info_at = '2026-10-01 12:00:00.123',
                                      position_updated_at = FROM_UNIXTIME(1790846400.456) WHERE id = $id"
        );

        $conversation = Conversation::findById($id, $a);
        $this->assertSame(gmmktime(12, 0, 0, 10, 1, 2026) * 1000 + 123, $conversation['updatedInfoAt']);
        $this->assertSame(1790846400456, $conversation['positionUpdatedAt']);
    }

    public function testNewConversationPositionIsCreationTime(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $before = (int) floor(microtime(true) * 1000);

        $conversation = Conversation::findOrCreateSingle($a, $b);

        $this->assertNull($conversation['lastMessage']);
        $this->assertTrue(abs($conversation['positionUpdatedAt'] - $before) <= 2000, 'positionUpdatedAt must be current time');
        $created = (int) DB::get()->query('SELECT ' . DB::ms('created_at') . ' FROM conversations')->fetchColumn();
        $this->assertSame($created, $conversation['positionUpdatedAt']);
        $this->assertSame($created, $conversation['updatedInfoAt']);
    }

    public function testFindMemberIds(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $id = Conversation::findOrCreateSingle($b, $a)['conversationId'];

        $this->assertSame([$a, $b], Conversation::findMemberIds($id));
        $this->assertSame([], Conversation::findMemberIds(42));
    }

    public function testFindByIdMissing(): void
    {
        $this->assertNull(Conversation::findById(42, 1));
    }

    public function testFindByIdForNonMemberViewer(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');
        $c = $this->insertUser('c');
        $id = Conversation::findOrCreateSingle($a, $b)['conversationId'];

        // Для постороннего собеседник — первый участник, отличный от него
        $this->assertSame($a, Conversation::findById($id, $c)['opponentUserId']);
    }

    public function testFindListByMemberOnlyOwnNewestFirst(): void
    {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');
        $c = $this->insertUser('c');

        $ab = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        $bc = Conversation::findOrCreateSingle($b, $c)['conversationId'];
        $ac = Conversation::findOrCreateSingle($a, $c)['conversationId'];

        $list = Conversation::findListByMember($a, 0, 10);
        $this->assertSame([$ac, $ab], array_column($list, 'conversationId'));
        $this->assertSame([$c, $b], array_column($list, 'opponentUserId'));

        $this->assertSame([$ab], array_column(Conversation::findListByMember($b, 1, 1), 'conversationId'));
    }

    public function testFindListByMemberOrdersByPosition(): void
    {
        $a  = $this->insertUser('a');
        $ab = Conversation::findOrCreateSingle($a, $this->insertUser('b'))['conversationId'];
        $ac = Conversation::findOrCreateSingle($a, $this->insertUser('c'))['conversationId'];
        $ad = Conversation::findOrCreateSingle($a, $this->insertUser('d'))['conversationId'];
        $this->setPosition($ab, 3000);
        $this->setPosition($ac, 1000);
        $this->setPosition($ad, 1000);

        // При равной позиции — по убыванию id
        $this->assertSame([$ab, $ad, $ac], array_column(Conversation::findListByMember($a, 0, 10), 'conversationId'));
        $this->assertSame([$ad, $ac], array_column(Conversation::findListByMember($a, 1, 10), 'conversationId'));
    }

    public function testFindListByMemberPositionUpdatedAfter(): void
    {
        $a  = $this->insertUser('a');
        $ab = Conversation::findOrCreateSingle($a, $this->insertUser('b'))['conversationId'];
        $ac = Conversation::findOrCreateSingle($a, $this->insertUser('c'))['conversationId'];
        $this->setPosition($ab, 2000);
        $this->setPosition($ac, 1000);

        $this->assertSame([$ab], array_column(Conversation::findListByMember($a, 0, 10, 1000), 'conversationId'));
        $this->assertSame([$ab, $ac], array_column(Conversation::findListByMember($a, 0, 10, 999), 'conversationId'));
        $this->assertSame([], Conversation::findListByMember($a, 0, 10, 2000));
    }

    public function testFindInfoUpdatedByMemberWithCursor(): void
    {
        $a  = $this->insertUser('a');
        $ab = Conversation::findOrCreateSingle($a, $this->insertUser('b'))['conversationId'];
        $ac = Conversation::findOrCreateSingle($a, $this->insertUser('c'))['conversationId'];
        $ad = Conversation::findOrCreateSingle($a, $this->insertUser('d'))['conversationId'];
        $this->setUpdatedInfo($ab, 3000);
        $this->setUpdatedInfo($ac, 2000);
        $this->setUpdatedInfo($ad, 2000);

        $ids = fn(array $list) => array_column($list, 'conversationId');
        // По возрастанию времени, при равенстве — по возрастанию id
        $this->assertSame([$ac, $ad, $ab], $ids(Conversation::findInfoUpdatedByMember($a, 1999, null, 10)));
        $this->assertSame([$ab], $ids(Conversation::findInfoUpdatedByMember($a, 2000, null, 10)));
        // Курсор: (2000, ac) — следующие ad и ab
        $this->assertSame([$ad, $ab], $ids(Conversation::findInfoUpdatedByMember($a, 2000, $ac, 10)));
        $this->assertSame([$ad], $ids(Conversation::findInfoUpdatedByMember($a, 2000, $ac, 1)));
        $this->assertSame([], Conversation::findInfoUpdatedByMember($a, 3000, $ab, 10));
    }

    public function testFindByIds(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $ab = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        $bc = Conversation::findOrCreateSingle($b, $this->insertUser('c'))['conversationId'];
        $ad = Conversation::findOrCreateSingle($a, $this->insertUser('d'))['conversationId'];

        $found = Conversation::findByIds([$ad, $bc, $ab, 999, $ad], $a);

        // Чужая bc и несуществующая 999 в результат не попадают
        ksort($found);
        $this->assertSame([$ab, $ad], array_keys($found));
        $this->assertSame(Conversation::findById($ab, $a), $found[$ab]);
        $this->assertSame([], Conversation::findByIds([], $a));
    }

    public function testLastMessageFollowsSendAndEdit(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $id = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        $updatedInfoAt = Conversation::findById($id, $a)['updatedInfoAt'];

        $first  = Message::send($id, $a, 'one');
        $second = Message::send($id, $b, 'two');

        $conversation = Conversation::findById($id, $a);
        $this->assertSame(Message::toLastMessage($second), $conversation['lastMessage']);
        $this->assertSame($second['createdAt'], $conversation['positionUpdatedAt']);
        $this->assertSame($updatedInfoAt, $conversation['updatedInfoAt'], 'New message does not change updatedInfoAt');

        // Правка не последнего сообщения переписку не меняет
        Message::edit($first['messageId'], 'one!', $isLast);
        $this->assertFalse($isLast);
        $this->assertSame($conversation, Conversation::findById($id, $a));

        // Правка последнего — новый lastMessage и updatedInfoAt, позиция прежняя
        $edited = Message::edit($second['messageId'], 'two!', $isLast);
        $this->assertTrue($isLast);
        $conversation = Conversation::findById($id, $a);
        $this->assertSame(Message::toLastMessage($edited), $conversation['lastMessage']);
        $this->assertSame($edited['updatedAt'], $conversation['updatedInfoAt']);
        $this->assertSame($second['createdAt'], $conversation['positionUpdatedAt']);
    }

    public function testFindListByMemberEmpty(): void
    {
        $this->assertSame([], Conversation::findListByMember($this->insertUser('a'), 0, 10));
    }

    public function testGroupHasNoOpponent(): void
    {
        $a  = $this->insertUser('a');
        $db = DB::get();
        $db->exec("INSERT INTO conversations (type, name) VALUES ('group', 'Team')");
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)')->execute([$id, $a]);

        $conversation = Conversation::findById($id, $a);

        $this->assertSame('Team', $conversation['name']);
        $this->assertSame([$a], $conversation['memberIds']);
        $this->assertNull($conversation['opponentUserId']);
    }

    private function setPosition(int $conversationId, int $ms): void
    {
        DB::get()->prepare('UPDATE conversations SET position_updated_at = ' . DB::fromMs() . ' WHERE id = ?')
            ->execute([$ms, $conversationId]);
    }

    private function setUpdatedInfo(int $conversationId, int $ms): void
    {
        DB::get()->prepare('UPDATE conversations SET updated_info_at = ' . DB::fromMs() . ' WHERE id = ?')
            ->execute([$ms, $conversationId]);
    }
}
