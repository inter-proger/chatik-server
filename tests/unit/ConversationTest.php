<?php

require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

class ConversationTest extends TestCase
{
    public function testFromRow(): void
    {
        $conversation = Conversation::fromRow(
            ['id' => '3', 'type' => 'group', 'name' => 'Team', 'position_updated_at' => '1790846400123',
             'updated_info_at' => '1790846000456', 'f_id' => null, 'lm_id' => null,
             'unread_count' => '2', 'last_read_message_id' => '15', 'change_version' => '118'],
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
            'unreadCount'       => 2,
            'lastReadMessageId' => 15,
            'changeVersion'     => 118,
        ], $conversation);
    }

    public function testFromRowWithLastMessage(): void
    {
        $conversation = Conversation::fromRow([
            'id' => 3, 'type' => 'group', 'name' => 'Team', 'position_updated_at' => 1, 'updated_info_at' => 1,
            'unread_count' => 0, 'last_read_message_id' => 0, 'change_version' => 0,
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
            'unread_count' => 0, 'last_read_message_id' => 0, 'change_version' => 0,
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

    public function testFindChangedByMember(): void
    {
        $a  = $this->insertUser('a');
        $ab = Conversation::findOrCreateSingle($a, $this->insertUser('b'))['conversationId'];
        $ac = Conversation::findOrCreateSingle($a, $this->insertUser('c'))['conversationId'];
        $ad = Conversation::findOrCreateSingle($a, $this->insertUser('d'))['conversationId'];

        $ids = fn(array $list) => array_column($list, 'conversationId');
        // Создание — версии 1, 2, 3; по возрастанию версии
        $this->assertSame([$ab, $ac, $ad], $ids(Conversation::findChangedByMember($a, 0, 10)));
        $this->assertSame([$ac, $ad], $ids(Conversation::findChangedByMember($a, 1, 10)));
        $this->assertSame([$ac], $ids(Conversation::findChangedByMember($a, 1, 1)));

        // Изменение уходит в конец выдачи с новой версией
        Message::send($ab, $a, 'Hi');
        $list = Conversation::findChangedByMember($a, 2, 10);
        $this->assertSame([$ad, $ab], $ids($list));
        $this->assertSame([3, 4], array_column($list, 'changeVersion'));
        $this->assertSame([], Conversation::findChangedByMember($a, 4, 10));
    }

    public function testChangeVersionGrowsOnEveryChange(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $id = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        $versions = fn() => [Conversation::findTotalUnreadCount($a)['changeVersion'], Conversation::findTotalUnreadCount($b)['changeVersion']];
        $this->assertSame([1, 1], $versions());

        $first = Message::send($id, $a, 'one', null, $isNew, $readInfos);
        $this->assertSame([2, 2], $versions());
        $this->assertSame([2, 2], array_column($readInfos, 'changeVersion'));

        Message::send($id, $b, 'two');
        $this->assertSame([3, 3], $versions());

        // Правка не последнего сообщения версию не меняет, последнего — меняет у всех
        Message::edit($first['messageId'], 'one!', $isLast, $changeVersions);
        $this->assertNull($changeVersions);
        $this->assertSame([3, 3], $versions());

        $last = Message::findList($id, null, 1)['messages'][0];
        Message::edit($last['messageId'], 'two!', $isLast, $changeVersions);
        $this->assertSame([$a => 4, $b => 4], $changeVersions);
        $this->assertSame(4, Conversation::findById($id, $a)['changeVersion']);

        // Прочтение — только у читающего
        Conversation::markRead($id, $a, $last['messageId']);
        $this->assertSame([5, 4], $versions());
    }

    public function testSendUpdatesUnreadCounters(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $c  = $this->insertUser('c');
        $ab = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        $bc = Conversation::findOrCreateSingle($b, $c)['conversationId'];

        Message::send($ab, $a, 'one');
        $two = Message::send($ab, $a, 'two', null, $isNew, $readInfos);
        Message::send($bc, $c, 'three');

        // Получатель: два непрочитанных в ab, одно в bc
        $this->assertSame([
            'conversationId' => $ab, 'lastReadMessageId' => 0, 'unreadCount' => 2, 'totalUnreadCount' => 2, 'changeVersion' => 4,
        ], $readInfos[$b]);
        // Отправитель: указатель на своём сообщении
        $this->assertSame([
            'conversationId' => $ab, 'lastReadMessageId' => $two['messageId'], 'unreadCount' => 0, 'totalUnreadCount' => 0, 'changeVersion' => 3,
        ], $readInfos[$a]);
        $this->assertSame(3, Conversation::findTotalUnreadCount($b)['totalUnreadCount']);
        $this->assertSame(2, Conversation::findById($ab, $b)['unreadCount']);

        // Ответ отмечает прочитанной всю переписку до него
        $reply = Message::send($ab, $b, 'reply', null, $isNew, $readInfos);
        $this->assertSame(0, $readInfos[$b]['unreadCount']);
        $this->assertSame($reply['messageId'], $readInfos[$b]['lastReadMessageId']);
        $this->assertSame(1, $readInfos[$b]['totalUnreadCount']);
        $this->assertSame(1, $readInfos[$a]['unreadCount']);
        $this->assertUnreadInvariant();
    }

    public function testSendRetryDoesNotChangeCounters(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $id = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        Message::send($id, $a, 'one', 'retry');

        Message::send($id, $a, 'one', 'retry', $isNew, $readInfos);

        $this->assertFalse($isNew);
        $this->assertNull($readInfos);
        $this->assertSame(['totalUnreadCount' => 1, 'changeVersion' => 2], Conversation::findTotalUnreadCount($b));
    }

    public function testMarkRead(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $id = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        $ids = array_map(fn($i) => Message::send($id, $a, "m$i")['messageId'], range(1, 3));

        // Прочитано до середины — непрочитанным остаётся одно
        $readInfo = Conversation::markRead($id, $b, $ids[1], $changed);
        $this->assertTrue($changed);
        $this->assertSame([
            'conversationId' => $id, 'lastReadMessageId' => $ids[1], 'unreadCount' => 1, 'totalUnreadCount' => 1, 'changeVersion' => 5,
        ], $readInfo);

        // Старое или то же сообщение ничего не меняет и версию не увеличивает
        $this->assertSame($readInfo, Conversation::markRead($id, $b, $ids[0], $changed));
        $this->assertFalse($changed);
        $this->assertSame($readInfo, Conversation::markRead($id, $b, $ids[1], $changed));
        $this->assertFalse($changed);

        $readInfo = Conversation::markRead($id, $b, $ids[2], $changed);
        $this->assertTrue($changed);
        $this->assertSame([0, 0, 6], [$readInfo['unreadCount'], $readInfo['totalUnreadCount'], $readInfo['changeVersion']]);
        $this->assertSame($readInfo['lastReadMessageId'], Conversation::findById($id, $b)['lastReadMessageId']);
        $this->assertUnreadInvariant();
    }

    public function testBrokenCounterDoesNotBlockSendAndMarkRead(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $id = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        $message = Message::send($id, $a, 'Hi');
        // Сломанный инвариант: общий счётчик меньше счётчика переписки
        DB::get()->exec("UPDATE users SET total_unread_count = 0 WHERE id = $b");

        $this->assertSame(0, Conversation::markRead($id, $b, $message['messageId'])['totalUnreadCount']);

        Message::send($id, $a, 'Hi again');
        DB::get()->exec("UPDATE users SET total_unread_count = 0 WHERE id = $b");
        Message::send($id, $b, 'reply', null, $isNew, $readInfos);
        $this->assertSame(0, $readInfos[$b]['totalUnreadCount']);
    }

    public function testRecountUnread(): void
    {
        $a  = $this->insertUser('a');
        $b  = $this->insertUser('b');
        $id = Conversation::findOrCreateSingle($a, $b)['conversationId'];
        Message::send($id, $a, 'one');
        Message::send($id, $a, 'two');
        DB::get()->exec('UPDATE conversation_members SET unread_count = 7');
        DB::get()->exec('UPDATE users SET total_unread_count = 9');

        Conversation::recountUnread($b);

        $this->assertSame(2, Conversation::findById($id, $b)['unreadCount']);
        $this->assertSame(2, Conversation::findTotalUnreadCount($b)['totalUnreadCount']);
        // Другие пользователи не тронуты
        $this->assertSame(9, Conversation::findTotalUnreadCount($a)['totalUnreadCount']);

        Conversation::recountUnread();
        $this->assertSame(0, Conversation::findTotalUnreadCount($a)['totalUnreadCount']);
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
