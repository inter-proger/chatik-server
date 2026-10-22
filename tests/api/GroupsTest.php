<?php

require_once __DIR__ . '/../../core/Group.php';
require_once __DIR__ . '/../../core/Conversation.php';
require_once __DIR__ . '/../../core/Message.php';

/** Методы /groups/* и события группы groups */
class GroupsTest extends TestCase
{
    private ApiClient $client;
    private int $me;
    private ApiClient $bobClient;
    private int $bob;
    private ApiClient $carolClient;
    private int $carol;

    public function setUp(): void
    {
        parent::setUp();
        [$this->client, $user] = $this->signup('me', 'Me');
        $this->me = $user['userId'];
        [$this->bobClient, $bob] = $this->signup('bob', 'Bob');
        $this->bob = $bob['userId'];
        [$this->carolClient, $carol] = $this->signup('carol', 'Carol');
        $this->carol = $carol['userId'];
    }

    // ---- /groups/create ----

    public function testCreate(): void
    {
        $res = $this->client->post('/groups/create', ['name' => '  Team 🚀! ', 'description' => " About\u{00A0}"]);

        $this->assertSuccess($res);
        $createdAt = (int) DB::get()->query('SELECT ' . DB::ms('created_at') . ' FROM conversations')->fetchColumn();
        $this->assertSame([
            'conversationId'    => 1,
            'type'              => 'group',
            'memberIds'         => null,
            'name'              => 'Team 🚀!',
            'description'       => 'About',
            'avatar'            => null,
            'opponentUserId'    => null,
            'lastMessage'       => null,
            'positionUpdatedAt' => $createdAt,
            'updatedInfoAt'     => $createdAt,
            'unreadCount'       => 0,
            'lastReadMessageId' => 0,
            'changeVersion'     => 1,
        ], $res['response']['conversation']);
        $this->assertSame([
            'conversationId' => 1,
            'version'        => 1,
            'members'        => [['userId' => $this->me, 'role' => 'owner', 'joinedAfterMessageId' => 0]],
        ], $res['response']['groupInfo']);
        $this->assertUsersAction([$this->me], $res);
    }

    public function testCreateWithAvatarAndWithoutDescription(): void
    {
        $fileId = $this->insertFile($this->me);

        $res = $this->client->post('/groups/create', ['name' => 'Team', 'description' => '   ', 'avatarFileId' => $fileId]);

        $this->assertSuccess($res);
        $this->assertSame($fileId, $res['response']['conversation']['avatar']['fileId']);
        $this->assertNull($res['response']['conversation']['description']);
    }

    public function testCreateValidation(): void
    {
        foreach (['', "  \u{200B}", str_repeat('я', User::NAME_MAX_LENGTH + 1)] as $name) {
            $this->assertError(ErrorCode::INVALID_NAME, $this->client->post('/groups/create', ['name' => $name]));
        }
        $this->assertSuccess($this->client->post('/groups/create', ['name' => str_repeat('я', User::NAME_MAX_LENGTH)]));

        $this->assertError(ErrorCode::INVALID_DESCRIPTION, $this->client->post('/groups/create', [
            'name' => 'Team', 'description' => str_repeat('я', Group::DESCRIPTION_MAX_LENGTH + 1),
        ]));

        $foreign = $this->insertFile($this->bob);
        $other   = $this->insertFile($this->me, 'other', []);
        foreach (['abc', '0', '999', $foreign] as $fileId) {
            $this->assertError(ErrorCode::FILE_NOT_FOUND, $this->client->post('/groups/create', ['name' => 'Team', 'avatarFileId' => $fileId]));
        }
        $this->assertError(ErrorCode::FILE_NOT_IMAGE, $this->client->post('/groups/create', ['name' => 'Team', 'avatarFileId' => $other]));

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->post('/groups/create', ['name' => 'Team']));
    }

    public function testCreatedEvent(): void
    {
        $mine = $this->wsConnect($this->client, $this->me);
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $res = $this->client->post('/groups/create', ['name' => 'Team']);

        $this->assertEvent('groups', 'created', [
            'conversation' => $res['response']['conversation'],
            'groupInfo'    => $res['response']['groupInfo'],
        ], $mine->receive(), [$this->me]);
        $this->assertNull($bobs->receive(0.3));
    }

    // ---- /groups/addMembers ----

    public function testAddMembers(): void
    {
        $id = $this->createGroup();
        $last = $this->send($this->client, $id, 'Hi');
        $myVersion = $this->changeVersion($this->me);

        $res = $this->client->post('/groups/addMembers', [
            'conversationId' => $id, 'userIds' => "$this->carol,$this->bob,$this->carol,$this->me,999",
        ]);

        $this->assertSuccess($res);
        $this->assertSame([$this->carol, $this->bob], $res['response']['addedUserIds']);
        $this->assertSame([
            ['userId' => $this->me, 'errorCode' => ErrorCode::GROUP_ALREADY_MEMBER],
            ['userId' => 999, 'errorCode' => ErrorCode::USER_NOT_FOUND],
        ], $res['response']['notAddedUsersInfo']);
        $this->assertSame([
            'conversationId' => $id,
            'version'        => 2,
            'members'        => [
                ['userId' => $this->me, 'role' => 'owner', 'joinedAfterMessageId' => 0],
                ['userId' => $this->bob, 'role' => 'member', 'joinedAfterMessageId' => $last],
                ['userId' => $this->carol, 'role' => 'member', 'joinedAfterMessageId' => $last],
            ],
        ], $res['response']['groupInfo']);
        $this->assertUsersAction([$this->bob, $this->carol], $res);

        // Версия владельца не растёт; добавленный видит группу без непрочитанных
        $this->assertSame($myVersion, $this->changeVersion($this->me));
        $bobs = $this->bobClient->get('/conversations/getBatch', ['conversationIds' => $id]);
        $this->assertSame(0, $bobs['response']['conversations'][0]['unreadCount']);
        $this->assertSame($last, $bobs['response']['conversations'][0]['lastReadMessageId']);
        $this->assertUsersAction([$this->me], $bobs);
        $this->assertUnreadInvariant();
    }

    public function testAddMembersNobodyAdded(): void
    {
        $id = $this->createGroup();
        $mine = $this->wsConnect($this->client, $this->me);

        $res = $this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => "$this->me,999"]);

        $this->assertSuccess($res);
        $this->assertSame([], $res['response']['addedUserIds']);
        $this->assertSame(1, $res['response']['groupInfo']['version']);
        $this->assertFalse(array_key_exists('actions', $res));
        $this->assertNull($mine->receive(0.3));
    }

    public function testAddMembersLimit(): void
    {
        $id = $this->createGroup();
        $userIds = [];
        for ($i = 0; $i < Group::MAX_MEMBERS; $i++) {
            $userIds[] = $this->insertUser("u$i");
        }

        // Владелец + 98 — до лимита остаётся одно место
        $this->assertSuccess($this->client->post('/groups/addMembers', [
            'conversationId' => $id, 'userIds' => implode(',', array_slice($userIds, 0, 98)),
        ]));
        $res = $this->client->post('/groups/addMembers', [
            'conversationId' => $id, 'userIds' => implode(',', array_slice($userIds, 98)),
        ]);

        $this->assertSame([$userIds[98]], $res['response']['addedUserIds']);
        $this->assertSame([['userId' => $userIds[99], 'errorCode' => ErrorCode::GROUP_MEMBERS_LIMIT]], $res['response']['notAddedUsersInfo']);
        $this->assertCount(Group::MAX_MEMBERS, $res['response']['groupInfo']['members']);
    }

    public function testAddMembersErrors(): void
    {
        $id = $this->createGroup([$this->bob]);
        $single = Conversation::findOrCreateSingle($this->me, $this->carol)['conversationId'];

        foreach (['', '1,x', implode(',', range(1, Group::MAX_MEMBERS + 1))] as $userIds) {
            $this->assertError(ErrorCode::INVALID_PARAMS, $this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $userIds]));
        }
        foreach (['', 'abc', '999'] as $conversationId) {
            $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->client->post('/groups/addMembers', ['conversationId' => $conversationId, 'userIds' => $this->carol]));
        }
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->carolClient->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $this->carol]));
        $this->assertError(ErrorCode::CONVERSATION_NOT_GROUP, $this->client->post('/groups/addMembers', ['conversationId' => $single, 'userIds' => $this->bob]));
        $this->assertError(ErrorCode::GROUP_FORBIDDEN, $this->bobClient->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $this->carol]));
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $this->carol]));
    }

    public function testMembersAddedEvent(): void
    {
        $id = $this->createGroup([$this->bob]);
        $mine   = $this->wsConnect($this->client, $this->me);
        $bobs   = $this->wsConnect($this->bobClient, $this->bob);
        $carols = $this->wsConnect($this->carolClient, $this->carol);

        $res = $this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $this->carol]);

        $body = ['conversationId' => $id, 'addedUserIds' => [$this->carol], 'groupInfo' => $res['response']['groupInfo']];
        $this->assertEvent('groups', 'membersAdded', $body + ['changeVersion' => null], $mine->receive(), [$this->carol]);
        $this->assertEvent('groups', 'membersAdded', $body + ['changeVersion' => null], $bobs->receive(), [$this->carol]);
        $this->assertEvent('groups', 'membersAdded', $body + ['changeVersion' => $this->changeVersion($this->carol)], $carols->receive(), [$this->carol]);
    }

    public function testAddedMemberSeesGroupOnTop(): void
    {
        $id = $this->createGroup();
        $this->send($this->client, $id, 'old');
        $single = $this->bobClient->post('/conversations/createSingle', ['userId' => $this->carol])['response']['conversation']['conversationId'];
        $this->send($this->bobClient, $single, 'recent');
        $maxPosition = $this->bobClient->get('/conversations/getList')['response']['conversations'][0]['positionUpdatedAt'];
        // Сообщения группы — в прошлом
        DB::get()->prepare('UPDATE conversations SET position_updated_at = ' . DB::fromMs() . ' WHERE id = ?')->execute([1000, $id]);

        $this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $this->bob]);

        $list = $this->bobClient->get('/conversations/getList')['response']['conversations'];
        $this->assertSame([$id, $single], array_column($list, 'conversationId'));
        $synced = $this->bobClient->get('/conversations/getList', ['positionUpdatedAfter' => $maxPosition]);
        $this->assertSame([$id], array_column($synced['response']['conversations'], 'conversationId'));
        // Отправитель последнего сообщения группы — в action users, хотя состава в Conversation нет
        $this->assertUsersAction([$this->me], $synced);
    }

    // ---- /groups/removeMembers ----

    public function testRemoveMembers(): void
    {
        $id = $this->createGroup([$this->bob, $this->carol]);

        $res = $this->client->post('/groups/removeMembers', ['conversationId' => $id, 'userIds' => "$this->bob,$this->me,999"]);

        $this->assertSuccess($res);
        $this->assertSame([$this->bob], $res['response']['removedUserIds']);
        $this->assertSame([
            ['userId' => $this->me, 'errorCode' => ErrorCode::GROUP_OWNER_TARGET],
            ['userId' => 999, 'errorCode' => ErrorCode::GROUP_NOT_MEMBER],
        ], $res['response']['notRemovedUsersInfo']);
        $this->assertSame([$this->me, $this->carol], Group::memberIdsOf($res['response']['groupInfo']));
        $this->assertSame(3, $res['response']['groupInfo']['version']);
        $this->assertFalse(array_key_exists('actions', $res));
    }

    public function testRemovedMemberLosesAccessButMessagesStay(): void
    {
        $id = $this->createGroup([$this->bob]);
        $bobMessage = $this->sendMessage($this->bobClient, $id, 'from bob');
        $this->send($this->client, $id, 'unread by bob');
        $bobVersion = $this->changeVersion($this->bob);

        $this->client->post('/groups/removeMembers', ['conversationId' => $id, 'userIds' => $this->bob]);

        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->bobClient->get('/messages/getList', ['conversationId' => $id]));
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->bobClient->post('/messages/send', ['conversationId' => $id, 'text' => 'Hi', 'clientId' => 'x']));
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->bobClient->post('/conversations/markRead', ['conversationId' => $id, 'messageId' => $bobMessage['messageId']]));
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->bobClient->get('/conversations/getMembersReadMeta', ['conversationId' => $id]));
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->bobClient->get('/groups/getInfo', ['conversationId' => $id]));
        $this->assertError(ErrorCode::MESSAGE_BLOCK_NOT_FOUND, $this->bobClient->get('/messages/getBlock', ['blockId' => $bobMessage['blockId']]));
        $this->assertError(ErrorCode::MESSAGE_NOT_FOUND, $this->bobClient->post('/messages/edit', ['messageId' => $bobMessage['messageId'], 'newText' => 'edited']));
        $this->assertSame([$id], $this->bobClient->get('/conversations/getBatch', ['conversationIds' => $id])['response']['notFoundIds']);

        // Сообщение удалённого остаётся в группе
        $mine = $this->client->get('/messages/getList', ['conversationId' => $id])['response'];
        $this->assertTrue(in_array($bobMessage, $mine['messages'], true), 'Removed member message is gone');

        // Метка удаления — в синхронизации changedAfter; непрочитанные группы вычтены
        $synced = $this->bobClient->get('/conversations/getList', ['changedAfter' => $bobVersion])['response'];
        $this->assertSame([], $synced['conversations']);
        $this->assertSame([['conversationId' => $id, 'changeVersion' => $bobVersion + 1]], $synced['removedConversations']);
        $this->assertSame(0, $synced['totalUnreadCount']);
        $this->assertUnreadInvariant();
    }

    public function testMembersRemovedEvent(): void
    {
        $id = $this->createGroup([$this->bob, $this->carol]);
        $this->send($this->client, $id, 'Hi');
        $bobs   = $this->wsConnect($this->bobClient, $this->bob);
        $carols = $this->wsConnect($this->carolClient, $this->carol);

        $res = $this->client->post('/groups/removeMembers', ['conversationId' => $id, 'userIds' => $this->bob]);

        $body = ['conversationId' => $id, 'removedUserIds' => [$this->bob]];
        $this->assertEvent('groups', 'membersRemoved', $body + [
            'groupInfo'           => null,
            'removedConversation' => ['conversationId' => $id, 'changeVersion' => $this->changeVersion($this->bob)],
            'totalUnreadCount'    => 0,
        ], $bobs->receive(), [$this->bob]);
        $this->assertEvent('groups', 'membersRemoved', $body + [
            'groupInfo' => $res['response']['groupInfo'], 'removedConversation' => null, 'totalUnreadCount' => null,
        ], $carols->receive(), [$this->bob]);
    }

    public function testReAddedMemberGetsGroupBack(): void
    {
        $id = $this->createGroup([$this->bob]);
        $this->bobClient->post('/conversations/markRead', [
            'conversationId' => $id, 'messageId' => $this->send($this->client, $id, 'one'),
        ]);
        $this->client->post('/groups/removeMembers', ['conversationId' => $id, 'userIds' => $this->bob]);
        $last = $this->send($this->client, $id, 'two');
        $bobVersion = $this->changeVersion($this->bob);

        $this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $this->bob]);

        $synced = $this->bobClient->get('/conversations/getList', ['changedAfter' => 0])['response'];
        $this->assertSame([], $synced['removedConversations']);
        $this->assertSame([$id], array_column($synced['conversations'], 'conversationId'));
        $this->assertSame($bobVersion + 1, $synced['conversations'][0]['changeVersion']);
        $this->assertSame($last, $synced['conversations'][0]['lastReadMessageId']);
    }

    public function testChangedAfterPagesMixRemovalsAndConversations(): void
    {
        $id = $this->createGroup();
        $single = $this->bobClient->post('/conversations/createSingle', ['userId' => $this->carol])['response']['conversation']['conversationId']; // версия bob: 1
        $this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $this->bob]);                                            // 2
        $this->client->post('/groups/removeMembers', ['conversationId' => $id, 'userIds' => $this->bob]);                                         // 3
        $this->send($this->bobClient, $single, 'Hi');                                                                                             // 4

        // Лишняя запись страницы — переписка с версией 4, метка с версией 3 остаётся
        $page = $this->bobClient->get('/conversations/getList', ['changedAfter' => 0, 'count' => 1])['response'];
        $this->assertSame([], $page['conversations']);
        $this->assertSame([['conversationId' => $id, 'changeVersion' => 3]], $page['removedConversations']);
        $this->assertTrue($page['hasNext']);

        $page = $this->bobClient->get('/conversations/getList', ['changedAfter' => 3, 'count' => 1])['response'];
        $this->assertSame([[$single, 4]], array_map(fn($c) => [$c['conversationId'], $c['changeVersion']], $page['conversations']));
        $this->assertSame([], $page['removedConversations']);
        $this->assertFalse($page['hasNext']);
    }

    public function testChangedAfterPageDropsNewerRemoval(): void
    {
        $id = $this->createGroup();
        $single = $this->bobClient->post('/conversations/createSingle', ['userId' => $this->carol])['response']['conversation']['conversationId']; // версия bob: 1
        $this->send($this->bobClient, $single, 'Hi');                                                                                             // 2
        $this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $this->bob]);                                            // 3
        $this->client->post('/groups/removeMembers', ['conversationId' => $id, 'userIds' => $this->bob]);                                         // 4

        // Лишняя запись страницы — метка с версией 4, переписка с версией 2 остаётся
        $page = $this->bobClient->get('/conversations/getList', ['changedAfter' => 0, 'count' => 1])['response'];
        $this->assertSame([[$single, 2]], array_map(fn($c) => [$c['conversationId'], $c['changeVersion']], $page['conversations']));
        $this->assertSame([], $page['removedConversations']);
        $this->assertTrue($page['hasNext']);

        $page = $this->bobClient->get('/conversations/getList', ['changedAfter' => 2, 'count' => 1])['response'];
        $this->assertSame([], $page['conversations']);
        $this->assertSame([['conversationId' => $id, 'changeVersion' => 4]], $page['removedConversations']);
        $this->assertFalse($page['hasNext']);
    }

    // ---- /groups/changeOwner ----

    public function testChangeOwner(): void
    {
        $id = $this->createGroup([$this->bob, $this->carol]);
        $carols = $this->wsConnect($this->carolClient, $this->carol);

        $res = $this->client->post('/groups/changeOwner', ['conversationId' => $id, 'newOwnerUserId' => $this->bob]);

        $this->assertSuccess($res);
        $groupInfo = $res['response']['groupInfo'];
        $this->assertSame(
            [$this->me => 'member', $this->bob => 'owner', $this->carol => 'member'],
            array_column($groupInfo['members'], 'role', 'userId')
        );
        $this->assertEvent('groups', 'ownerChanged', [
            'conversationId' => $id, 'newOwnerUserId' => $this->bob, 'groupInfo' => $groupInfo,
        ], $carols->receive(), [$this->bob]);
        // Бывший владелец прав больше не имеет
        $this->assertError(ErrorCode::GROUP_FORBIDDEN, $this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => 999]));
    }

    public function testChangeOwnerErrors(): void
    {
        $id = $this->createGroup([$this->bob]);

        $this->assertError(ErrorCode::GROUP_OWNER_TARGET, $this->client->post('/groups/changeOwner', ['conversationId' => $id, 'newOwnerUserId' => $this->me]));
        foreach ([$this->carol, '999', 'abc', ''] as $newOwner) {
            $this->assertError(ErrorCode::GROUP_NOT_MEMBER, $this->client->post('/groups/changeOwner', ['conversationId' => $id, 'newOwnerUserId' => $newOwner]));
        }
        $this->assertError(ErrorCode::GROUP_FORBIDDEN, $this->bobClient->post('/groups/changeOwner', ['conversationId' => $id, 'newOwnerUserId' => $this->bob]));
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->carolClient->post('/groups/changeOwner', ['conversationId' => $id, 'newOwnerUserId' => $this->bob]));
    }

    // ---- /groups/getInfo ----

    public function testGetInfo(): void
    {
        $id = $this->createGroup([$this->bob]);
        $single = Conversation::findOrCreateSingle($this->me, $this->carol)['conversationId'];

        $res = $this->bobClient->get('/groups/getInfo', ['conversationId' => $id]);

        $this->assertSuccess($res);
        $this->assertSame(['groupInfo' => Group::findInfo($id)], $res['response']);
        $this->assertUsersAction([$this->me, $this->bob], $res);
        $this->assertError(ErrorCode::CONVERSATION_NOT_GROUP, $this->client->get('/groups/getInfo', ['conversationId' => $single]));
        foreach (['', 'abc', '999'] as $conversationId) {
            $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->client->get('/groups/getInfo', ['conversationId' => $conversationId]));
        }
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->carolClient->get('/groups/getInfo', ['conversationId' => $id]));
    }

    // ---- /groups/leave ----

    public function testLeave(): void
    {
        $id = $this->createGroup([$this->bob]);
        $this->send($this->client, $id, 'unread');
        $mine = $this->wsConnect($this->client, $this->me);
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $res = $this->bobClient->post('/groups/leave', ['conversationId' => $id]);

        $this->assertSuccess($res);
        $removed = ['conversationId' => $id, 'changeVersion' => $this->changeVersion($this->bob)];
        $this->assertSame(['removedConversation' => $removed, 'totalUnreadCount' => 0], $res['response']);
        $body = ['conversationId' => $id, 'leftUserId' => $this->bob];
        $this->assertEvent('groups', 'memberLeft', $body + [
            'groupInfo' => Group::findInfo($id), 'removedConversation' => null, 'totalUnreadCount' => null,
        ], $mine->receive(), [$this->bob]);
        $this->assertEvent('groups', 'memberLeft', $body + [
            'groupInfo' => null, 'removedConversation' => $removed, 'totalUnreadCount' => 0,
        ], $bobs->receive(), [$this->bob]);
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->bobClient->post('/groups/leave', ['conversationId' => $id]));
        $this->assertUnreadInvariant();
    }

    public function testLeaveOwner(): void
    {
        $id = $this->createGroup([$this->bob]);
        $this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => $this->carol]);
        $carols = $this->wsConnect($this->carolClient, $this->carol);

        $this->assertSuccess($this->client->post('/groups/leave', ['conversationId' => $id]));

        // Владелец — вступивший раньше всех
        $groupInfo = Group::findInfo($id);
        $this->assertSame([$this->bob => 'owner', $this->carol => 'member'], array_column($groupInfo['members'], 'role', 'userId'));
        $this->assertEvent('groups', 'memberLeft', [
            'conversationId' => $id, 'leftUserId' => $this->me,
            'groupInfo' => $groupInfo, 'removedConversation' => null, 'totalUnreadCount' => null,
        ], $carols->receive(), [$this->me, $this->bob]);
    }

    public function testLeaveErrors(): void
    {
        $single = Conversation::findOrCreateSingle($this->me, $this->bob)['conversationId'];

        $this->assertError(ErrorCode::CONVERSATION_NOT_GROUP, $this->client->post('/groups/leave', ['conversationId' => $single]));
        $this->assertError(ErrorCode::CONVERSATION_NOT_FOUND, $this->client->post('/groups/leave', ['conversationId' => 'abc']));
        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $this->client()->post('/groups/leave', ['conversationId' => $single]));
    }

    // ---- Группа в остальных методах ----

    public function testMessagesInGroup(): void
    {
        $id = $this->createGroup([$this->bob, $this->carol]);
        $bobs = $this->wsConnect($this->bobClient, $this->bob);

        $message = $this->sendMessage($this->carolClient, $id, 'Hi all');

        $received = $bobs->receive();
        $this->assertSame('received', $received['event']);
        $this->assertSame(['messageId' => $message['messageId'], 'senderId' => $this->carol, 'type' => 'text', 'text' => 'Hi all', 'updatedAt' => $message['updatedAt']], $received['body']['lastMessage']);
        $this->assertSame(1, $received['body']['readInfo']['unreadCount']);

        $meta = $this->client->get('/conversations/getMembersReadMeta', ['conversationId' => $id]);
        $this->assertSame([$this->bob, $this->carol], array_column($meta['response']['membersReadMeta'], 'userId'));
        $this->assertUsersAction([$this->bob, $this->carol], $meta);
        $this->assertUnreadInvariant();
    }

    // ---- Вспомогательные ----

    /** Создаёт группу от имени текущего пользователя и добавляет участников $memberIds. */
    private function createGroup(array $memberIds = []): int
    {
        $id = $this->client->post('/groups/create', ['name' => 'Team'])['response']['conversation']['conversationId'];
        if ($memberIds) {
            $this->assertSuccess($this->client->post('/groups/addMembers', ['conversationId' => $id, 'userIds' => implode(',', $memberIds)]));
        }
        return $id;
    }

    /** Отправляет сообщение через API, возвращает объект Message. */
    private function sendMessage(ApiClient $client, int $conversationId, string $text): array
    {
        $res = $client->post('/messages/send', ['conversationId' => $conversationId, 'text' => $text, 'clientId' => bin2hex(random_bytes(8))]);
        $this->assertSuccess($res);

        return $res['response']['message'];
    }

    /** Отправляет сообщение через API, возвращает его id. */
    private function send(ApiClient $client, int $conversationId, string $text): int
    {
        return $this->sendMessage($client, $conversationId, $text)['messageId'];
    }

    private function changeVersion(int $userId): int
    {
        return Conversation::findTotalUnreadCount($userId)['changeVersion'];
    }
}
