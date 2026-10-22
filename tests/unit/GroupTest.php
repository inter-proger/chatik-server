<?php

require_once __DIR__ . '/../../core/Group.php';
require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

class GroupTest extends TestCase
{
    public function testCreate(): void
    {
        $a  = $this->insertUser('a');
        $id = Group::create($a, 'Team', 'About', null);

        $conversation = Conversation::findById($id, $a);
        $this->assertSame('group', $conversation['type']);
        $this->assertSame('Team', $conversation['name']);
        $this->assertSame('About', $conversation['description']);
        $this->assertNull($conversation['memberIds']);
        $this->assertSame(1, $conversation['changeVersion']);
        // Создатель вступил в момент создания — позиция = время создания
        $this->assertSame($conversation['updatedInfoAt'], $conversation['positionUpdatedAt']);

        $this->assertSame([
            'conversationId' => $id,
            'version'        => 1,
            'members'        => [['userId' => $a, 'role' => 'owner', 'joinedAfterMessageId' => 0]],
        ], Group::findInfo($id));
    }

    public function testFindInfoNotGroup(): void
    {
        $a = $this->insertUser('a');
        $single = Conversation::findOrCreateSingle($a, $this->insertUser('b'))['conversationId'];

        $this->assertNull(Group::findInfo($single));
        $this->assertNull(Group::findInfo(999));
    }

    public function testAddMembersSetsReadPointerAndJoinPoint(): void
    {
        [$id, $a] = $this->group();
        Message::send($id, $a, 'one');
        $last = Message::send($id, $a, 'two')['messageId'];
        $b = $this->insertUser('b');

        $result = Group::addMembers($id, $a, [$b], $error);

        $this->assertNull($error);
        $this->assertSame([$b], $result['addedUserIds']);
        $this->assertSame(['userId' => $b, 'role' => 'member', 'joinedAfterMessageId' => $last], $result['groupInfo']['members'][1]);
        // Вся история видна, но не непрочитана
        $conversation = Conversation::findById($id, $b);
        $this->assertSame(0, $conversation['unreadCount']);
        $this->assertSame($last, $conversation['lastReadMessageId']);
        $this->assertSame($result['changeVersions'][$b], $conversation['changeVersion']);
        $this->assertUnreadInvariant();
    }

    public function testAddMembersDoesNotChangeOthersVersion(): void
    {
        [$id, $a] = $this->group();
        $before = Conversation::findById($id, $a);

        Group::addMembers($id, $a, [$this->insertUser('b')]);

        $after = Conversation::findById($id, $a);
        $this->assertSame($before['changeVersion'], $after['changeVersion']);
        $this->assertSame($before['updatedInfoAt'], $after['updatedInfoAt']);
        $this->assertSame(2, Group::findInfo($id)['version']);
    }

    public function testAddedMemberPositionIsJoinTime(): void
    {
        [$id, $a] = $this->group();
        Message::send($id, $a, 'old');
        $b = $this->insertUser('b');
        // Сообщение и вступление владельца — в прошлом
        DB::get()->prepare('UPDATE conversations SET position_updated_at = ' . DB::fromMs() . ' WHERE id = ?')->execute([1000, $id]);
        DB::get()->prepare('UPDATE conversation_members SET created_at = ' . DB::fromMs() . ' WHERE user_id = ?')->execute([1000, $a]);

        Group::addMembers($id, $a, [$b]);

        $joinedAt = (int) DB::get()->query('SELECT ' . DB::ms('created_at') . " FROM conversation_members WHERE user_id = $b")->fetchColumn();
        $this->assertSame($joinedAt, Conversation::findById($id, $b)['positionUpdatedAt']);
        $this->assertSame(1000, Conversation::findById($id, $a)['positionUpdatedAt']);
        // Фильтр и сортировка — по позиции смотрящего
        $this->assertSame([$id], array_column(Conversation::findListByMember($b, 0, 10, $joinedAt - 1), 'conversationId'));
        $this->assertSame([], Conversation::findListByMember($a, 0, 10, 1000));
    }

    public function testRemoveMembersLeavesTombstoneAndSubtractsUnread(): void
    {
        [$id, $a] = $this->group();
        $b = $this->insertUser('b');
        Group::addMembers($id, $a, [$b]);
        Message::send($id, $a, 'one');
        Message::send($id, $a, 'two');

        $result = Group::removeMembers($id, $a, [$b], $error);

        $this->assertNull($error);
        $removed = $result['removed'][$b];
        $this->assertSame(0, $removed['totalUnreadCount']);
        $this->assertSame(Conversation::findTotalUnreadCount($b)['changeVersion'], $removed['removedConversation']['changeVersion']);
        $this->assertFalse(Conversation::isMember($id, $b));
        $this->assertSame(
            ['conversations' => [], 'removedConversations' => [$removed['removedConversation']]],
            Conversation::findChangedByMember($b, $removed['removedConversation']['changeVersion'] - 1, 10)
        );
        $this->assertUnreadInvariant();
    }

    public function testReAddRemovesTombstone(): void
    {
        [$id, $a] = $this->group();
        $b = $this->insertUser('b');
        Group::addMembers($id, $a, [$b]);
        Group::removeMembers($id, $a, [$b]);

        Group::addMembers($id, $a, [$b]);

        $changed = Conversation::findChangedByMember($b, 0, 10);
        $this->assertSame([], $changed['removedConversations']);
        $this->assertSame([$id], array_column($changed['conversations'], 'conversationId'));
    }

    public function testFindChangedByMemberMergesTombstonesByVersion(): void
    {
        [$id, $a] = $this->group();
        $b = $this->insertUser('b');
        $single = Conversation::findOrCreateSingle($b, $this->insertUser('c'))['conversationId']; // версия b: 1
        Group::addMembers($id, $a, [$b]);                                                         // 2
        Group::removeMembers($id, $a, [$b]);                                                      // 3
        Message::send($single, $b, 'Hi');                                                         // 4

        $changed = Conversation::findChangedByMember($b, 0, 2);
        $this->assertSame([$single], array_column($changed['conversations'], 'conversationId'));
        $this->assertSame([['conversationId' => $id, 'changeVersion' => 3]], $changed['removedConversations']);

        $changed = Conversation::findChangedByMember($b, 3, 10);
        $this->assertSame([4], array_column($changed['conversations'], 'changeVersion'));
        $this->assertSame([], $changed['removedConversations']);
    }

    public function testLeaveOwnerPassesToEarliestMember(): void
    {
        [$id, $a] = $this->group();
        $c = $this->insertUser('c');
        $b = $this->insertUser('b');
        Group::addMembers($id, $a, [$c]);
        DB::get()->exec("UPDATE conversation_members SET created_at = created_at - INTERVAL 1 SECOND WHERE user_id = $c");
        Group::addMembers($id, $a, [$b]);

        $result = Group::leave($id, $a, $error);

        $this->assertNull($error);
        $this->assertSame($c, $result['newOwnerId']);
        $roles = array_column($result['groupInfo']['members'], 'role', 'userId');
        $this->assertSame([$c => 'owner', $b => 'member'], $roles);
    }

    public function testLeaveLastMember(): void
    {
        [$id, $a] = $this->group();

        $result = Group::leave($id, $a, $error);

        $this->assertNull($error);
        $this->assertNull($result['newOwnerId']);
        $this->assertSame([], $result['groupInfo']['members']);
        $this->assertNull(DB::get()->query("SELECT owner_id FROM conversations WHERE id = $id")->fetchColumn());
        $this->assertNull(Group::leave($id, $a, $error));
        $this->assertSame(ErrorCode::CONVERSATION_NOT_FOUND, $error);
        // Сообщения пустой группы никуда не пишутся
        $this->assertNull(Message::send($id, $a, 'Hi'));
    }

    public function testAccessErrorsUnderLock(): void
    {
        [$id, $a] = $this->group();
        $b = $this->insertUser('b');
        $single = Conversation::findOrCreateSingle($a, $b)['conversationId'];

        $this->assertNull(Group::addMembers($id, $b, [$a], $error));
        $this->assertSame(ErrorCode::CONVERSATION_NOT_FOUND, $error);
        $this->assertNull(Group::addMembers($single, $a, [$b], $error));
        $this->assertSame(ErrorCode::CONVERSATION_NOT_GROUP, $error);

        Group::addMembers($id, $a, [$b]);
        $this->assertNull(Group::removeMembers($id, $b, [$a], $error));
        $this->assertSame(ErrorCode::GROUP_FORBIDDEN, $error);
        $this->assertNull(Group::changeOwner($id, $a, $a, $error));
        $this->assertSame(ErrorCode::GROUP_OWNER_TARGET, $error);
        $this->assertNull(Group::changeOwner($id, $a, 999, $error));
        $this->assertSame(ErrorCode::GROUP_NOT_MEMBER, $error);
        $this->assertSame(2, Group::findInfo($id)['version']);
    }

    // ---- Удаление между проверкой участия в обработчике и транзакцией ----

    public function testMarkReadAfterRemovalChangesNothing(): void
    {
        [$id, $a, $b] = $this->groupWithMember();
        $messageId = Message::send($id, $a, 'Hi')['messageId'];
        Group::removeMembers($id, $a, [$b]);
        $before = Conversation::findTotalUnreadCount($b);

        $this->assertNull(Conversation::markRead($id, $b, $messageId, $changed));

        $this->assertFalse($changed);
        $this->assertSame($before, Conversation::findTotalUnreadCount($b));
        $this->assertUnreadInvariant();
    }

    public function testSendAfterRemovalChangesNothing(): void
    {
        [$id, $a, $b] = $this->groupWithMember();
        Message::send($id, $b, 'mine', 'c1');
        Group::removeMembers($id, $a, [$b]);

        $this->assertNull(Message::send($id, $b, 'Hi', 'c2', $isNew, $readInfos));
        // Повтор с уже использованным clientId тоже не отдаёт сообщение удалённому
        $this->assertNull(Message::send($id, $b, 'mine', 'c1'));

        $this->assertNull($readInfos);
        $this->assertSame(1, (int) DB::get()->query('SELECT COUNT(*) FROM messages')->fetchColumn());
        $this->assertUnreadInvariant();
    }

    public function testEditAfterRemovalChangesNothing(): void
    {
        [$id, $a, $b] = $this->groupWithMember();
        $message = Message::send($id, $b, 'mine');
        Group::removeMembers($id, $a, [$b]);

        $this->assertNull(Message::edit($message['messageId'], 'edited'));

        $this->assertSame($message, Message::findById($message['messageId']));
    }

    /** Группа из одного владельца: [conversationId, ownerId]. */
    private function group(): array
    {
        $a = $this->insertUser('a');

        return [Group::create($a, 'Team', null, null), $a];
    }

    /** Группа владельца и участника: [conversationId, ownerId, memberId]. */
    private function groupWithMember(): array
    {
        [$id, $a] = $this->group();
        $b = $this->insertUser('b');
        Group::addMembers($id, $a, [$b]);

        return [$id, $a, $b];
    }
}
