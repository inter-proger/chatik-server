<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/ErrorCode.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/Conversation.php';
require_once __DIR__ . '/WebSocket.php';

/**
 * Групповые переписки: создание, состав, владелец, выход. Устройство — docs/architecture/Groups.md.
 *
 * Состав и роли — GroupInfo (conversationId, version, members), а не часть Conversation: изменение состава
 * не меняет Conversation остальных участников и их версию, только group_info_version группы.
 * Версия пользователя растёт у тех, чей список чатов меняется, — у добавленных и удалённых.
 *
 * Все изменения — под блокировкой строки переписки, порядок блокировок общий (DataModel.md):
 * переписка → строки участников → строки users по возрастанию id → вставка строк участников и меток.
 * Права и состав проверяются под блокировкой; ошибка возвращается через $error, данные не меняются.
 */
class Group
{
    /** Максимум участников группы, включая владельца */
    const MAX_MEMBERS = 100;

    /** Максимальная длина описания — по колонке conversations.description */
    const DESCRIPTION_MAX_LENGTH = 1024;

    const ROLE_OWNER  = 'owner';
    const ROLE_MEMBER = 'member';

    /** Название из запроса: нормализованный текст от 1 до User::NAME_MAX_LENGTH символов или null. */
    public static function normalizeName(mixed $name): ?string
    {
        $name = Request::normalizeText($name, User::NAME_MAX_LENGTH);

        return $name === '' ? null : $name;
    }

    /**
     * Описание из запроса. Не передано или пустое — '' (описания нет), длиннее
     * DESCRIPTION_MAX_LENGTH или не строка — null (невалидное).
     */
    public static function normalizeDescription(mixed $description): ?string
    {
        return Request::normalizeText($description ?? '', self::DESCRIPTION_MAX_LENGTH);
    }

    /**
     * Создаёт группу: создатель — единственный участник и владелец, версия GroupInfo — 1.
     * Создание меняет список чатов создателя — его версия растёт. Возвращает conversationId.
     */
    public static function create(int $ownerId, string $name, ?string $description, ?int $avatarFileId): int
    {
        $db = DB::get();
        $db->beginTransaction();
        try {
            // Версия — до вставок: и переписка (owner_id), и строка участника проверяют внешний ключ
            // и ставят S-блокировку на строку users; без уже взятой X-блокировки два параллельных
            // создания одним пользователем ждали бы друг друга
            $db->prepare('UPDATE users SET change_version = change_version + 1 WHERE id = ?')->execute([$ownerId]);

            $db->prepare(
                'INSERT INTO conversations (type, name, description, avatar_file_id, owner_id, group_info_version)
                 VALUES (?, ?, ?, ?, ?, 1)'
            )->execute([Conversation::TYPE_GROUP, $name, $description, $avatarFileId, $ownerId]);
            $id = (int) $db->lastInsertId();

            // Время вступления создателя — время создания группы
            $db->prepare(
                'INSERT INTO conversation_members (conversation_id, user_id, change_version, created_at)
                 SELECT c.id, u.id, u.change_version, c.created_at
                 FROM conversations c JOIN users u ON u.id = ?
                 WHERE c.id = ?'
            )->execute([$ownerId, $id]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * GroupInfo группы: conversationId, version, members — GroupMember (userId, role, joinedAfterMessageId)
     * по возрастанию userId. null — переписки нет или она не группа. Версия и состав — двумя запросами:
     * вызывающий снимает их в одной транзакции (снимок REPEATABLE READ или под блокировкой переписки).
     */
    public static function findInfo(int $conversationId): ?array
    {
        $db = DB::get();

        $stmt = $db->prepare('SELECT owner_id, group_info_version FROM conversations WHERE id = ? AND type = ?');
        $stmt->execute([$conversationId, Conversation::TYPE_GROUP]);
        $group = $stmt->fetch();
        if (!$group) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT user_id, joined_after_message_id FROM conversation_members WHERE conversation_id = ? ORDER BY user_id'
        );
        $stmt->execute([$conversationId]);

        $ownerId = $group['owner_id'] !== null ? (int) $group['owner_id'] : null;

        return [
            'conversationId' => $conversationId,
            'version'        => (int) $group['group_info_version'],
            'members'        => array_map(fn($row) => [
                'userId'               => (int) $row['user_id'],
                'role'                 => (int) $row['user_id'] === $ownerId ? self::ROLE_OWNER : self::ROLE_MEMBER,
                'joinedAfterMessageId' => (int) $row['joined_after_message_id'],
            ], $stmt->fetchAll()),
        ];
    }

    /** id участников из GroupInfo. */
    public static function memberIdsOf(array $groupInfo): array
    {
        return array_column($groupInfo['members'], 'userId');
    }

    /**
     * Ошибка доступа к группе для участника $userId, без блокировок (методы чтения):
     * CONVERSATION_NOT_FOUND — переписки нет или пользователь не участник, CONVERSATION_NOT_GROUP — не группа.
     * null — доступ есть.
     */
    public static function findAccessError(int $conversationId, int $userId): ?int
    {
        $stmt = DB::get()->prepare(
            'SELECT c.type FROM conversations c
             JOIN conversation_members m ON m.conversation_id = c.id AND m.user_id = ?
             WHERE c.id = ?'
        );
        $stmt->execute([$userId, $conversationId]);
        $type = $stmt->fetchColumn();

        if ($type === false) {
            return ErrorCode::CONVERSATION_NOT_FOUND;
        }
        return $type === Conversation::TYPE_GROUP ? null : ErrorCode::CONVERSATION_NOT_GROUP;
    }

    /**
     * Добавляет пользователей $userIds (по порядку) в группу от имени владельца $actorId.
     * Уже участники — GROUP_ALREADY_MEMBER, несуществующие — USER_NOT_FOUND, сверх лимита — GROUP_MEMBERS_LIMIT.
     * Новым участникам указатель прочтения — на последнее сообщение (история видна, но не непрочитана),
     * метки удаления снимаются; версия растёт у добавленных и у GroupInfo.
     *
     * Возвращает addedUserIds, notAddedUsersInfo (UserErrorInfo[]), groupInfo и changeVersions
     * (userId => новая версия добавленного) или null с $error (ошибка доступа, см. lock()).
     */
    public static function addMembers(int $conversationId, int $actorId, array $userIds, ?int &$error = null): ?array
    {
        $db = DB::get();
        $db->beginTransaction();
        try {
            $group = self::lock($conversationId, $actorId, true, $error);
            if ($group === null) {
                $db->rollBack();
                return null;
            }

            $memberIds = self::lockMemberIds($conversationId);

            // Кандидаты, которые ещё не участники: X-блокировка строк users по возрастанию id сразу
            // проверяет существование и защищает вставку участников ниже от дедлока на внешнем ключе
            $candidateIds = array_values(array_diff($userIds, $memberIds));
            $existingIds  = [];
            if ($candidateIds) {
                $stmt = $db->prepare(
                    'SELECT id FROM users WHERE id IN (' . self::placeholders($candidateIds) . ') ORDER BY id FOR UPDATE'
                );
                $stmt->execute($candidateIds);
                $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            }

            $added    = [];
            $notAdded = [];
            $count    = count($memberIds);
            foreach ($userIds as $userId) {
                if (in_array($userId, $memberIds, true)) {
                    $notAdded[] = self::userError($userId, ErrorCode::GROUP_ALREADY_MEMBER);
                } elseif (!in_array($userId, $existingIds, true)) {
                    $notAdded[] = self::userError($userId, ErrorCode::USER_NOT_FOUND);
                } elseif ($count >= self::MAX_MEMBERS) {
                    $notAdded[] = self::userError($userId, ErrorCode::GROUP_MEMBERS_LIMIT);
                } else {
                    $added[] = $userId;
                    $count++;
                }
            }

            $changeVersions = [];
            if ($added) {
                $sorted = $added;
                sort($sorted);
                $in = self::placeholders($sorted);

                $db->prepare("UPDATE users SET change_version = change_version + 1 WHERE id IN ($in) ORDER BY id")
                    ->execute($sorted);

                $db->prepare("DELETE FROM conversation_removed_members WHERE conversation_id = ? AND user_id IN ($in)")
                    ->execute([$conversationId, ...$sorted]);

                // Указатель и точка вступления — последнее сообщение на момент вступления
                $lastMessageId = (int) $group['last_message_id'];
                $db->prepare(
                    "INSERT INTO conversation_members
                         (conversation_id, user_id, last_read_message_id, unread_count, change_version, joined_after_message_id)
                     SELECT ?, id, ?, 0, change_version, ? FROM users WHERE id IN ($in)"
                )->execute([$conversationId, $lastMessageId, $lastMessageId, ...$sorted]);

                self::bumpInfoVersion($conversationId);

                $stmt = $db->prepare("SELECT id, change_version FROM users WHERE id IN ($in) ORDER BY id");
                $stmt->execute($sorted);
                $changeVersions = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
            }

            $groupInfo = self::findInfo($conversationId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return [
            'addedUserIds'      => $added,
            'notAddedUsersInfo' => $notAdded,
            'groupInfo'         => $groupInfo,
            'changeVersions'    => $changeVersions,
        ];
    }

    /**
     * Удаляет участников $userIds (по порядку) из группы от имени владельца $actorId.
     * Не участники (в том числе несуществующие) — GROUP_NOT_MEMBER, сам владелец — GROUP_OWNER_TARGET.
     *
     * Возвращает removedUserIds, notRemovedUsersInfo (UserErrorInfo[]), groupInfo и removed
     * (userId => removedConversation, totalUnreadCount — для событий удалённым) или null с $error.
     */
    public static function removeMembers(int $conversationId, int $actorId, array $userIds, ?int &$error = null): ?array
    {
        $db = DB::get();
        $db->beginTransaction();
        try {
            $group = self::lock($conversationId, $actorId, true, $error);
            if ($group === null) {
                $db->rollBack();
                return null;
            }

            $memberIds = self::lockMemberIds($conversationId);

            $removedIds = [];
            $notRemoved = [];
            foreach ($userIds as $userId) {
                if ($userId === (int) $group['owner_id']) {
                    $notRemoved[] = self::userError($userId, ErrorCode::GROUP_OWNER_TARGET);
                } elseif (!in_array($userId, $memberIds, true)) {
                    $notRemoved[] = self::userError($userId, ErrorCode::GROUP_NOT_MEMBER);
                } else {
                    $removedIds[] = $userId;
                }
            }

            $removed = [];
            if ($removedIds) {
                $removed = self::deleteMembers($conversationId, $removedIds);
                self::bumpInfoVersion($conversationId);
            }

            $groupInfo = self::findInfo($conversationId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return [
            'removedUserIds'      => $removedIds,
            'notRemovedUsersInfo' => $notRemoved,
            'groupInfo'           => $groupInfo,
            'removed'             => $removed,
        ];
    }

    /**
     * Передаёт владение участнику $newOwnerId от имени владельца $actorId; бывший владелец остаётся участником.
     * Не участник — GROUP_NOT_MEMBER, сам владелец — GROUP_OWNER_TARGET. Возвращает GroupInfo или null с $error.
     */
    public static function changeOwner(int $conversationId, int $actorId, int $newOwnerId, ?int &$error = null): ?array
    {
        $db = DB::get();
        $db->beginTransaction();
        try {
            $group = self::lock($conversationId, $actorId, true, $error);
            if ($group !== null && $newOwnerId === (int) $group['owner_id']) {
                $error = ErrorCode::GROUP_OWNER_TARGET;
            } elseif ($group !== null && !Conversation::lockMember($conversationId, $newOwnerId)) {
                $error = ErrorCode::GROUP_NOT_MEMBER;
            }
            if ($error !== null) {
                $db->rollBack();
                return null;
            }

            $db->prepare('UPDATE conversations SET owner_id = ? WHERE id = ?')->execute([$newOwnerId, $conversationId]);
            self::bumpInfoVersion($conversationId);

            $groupInfo = self::findInfo($conversationId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $groupInfo;
    }

    /**
     * Выход участника $userId из группы. Если выходит владелец, владельцем становится самый давний участник
     * (раньше вступил, при равенстве — меньший id); последний вышедший оставляет группу без участников
     * и без владельца — она не удаляется.
     *
     * Возвращает removedConversation, totalUnreadCount (для вышедшего), groupInfo после выхода и newOwnerId
     * (новый владелец или null, если владелец не менялся) или null с $error.
     */
    public static function leave(int $conversationId, int $userId, ?int &$error = null): ?array
    {
        $db = DB::get();
        $db->beginTransaction();
        try {
            $group = self::lock($conversationId, $userId, false, $error);
            if ($group === null) {
                $db->rollBack();
                return null;
            }

            self::lockMemberIds($conversationId);

            $newOwnerId = null;
            if ($userId === (int) $group['owner_id']) {
                $stmt = $db->prepare(
                    'SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id <> ?
                     ORDER BY created_at, user_id LIMIT 1'
                );
                $stmt->execute([$conversationId, $userId]);
                $next = $stmt->fetchColumn();
                $newOwnerId = $next !== false ? (int) $next : null;

                // owner_id проверяет внешний ключ и ставит S-блокировку на строку нового владельца —
                // раньше, чем deleteMembers() возьмёт X на вышедшего. Обе строки — заранее, по возрастанию id,
                // иначе цикл с транзакцией, блокирующей users по порядку (отправка в общую переписку)
                $lockIds = array_values(array_filter([$userId, $newOwnerId]));
                sort($lockIds);
                $db->prepare('SELECT id FROM users WHERE id IN (' . self::placeholders($lockIds) . ') ORDER BY id FOR UPDATE')
                    ->execute($lockIds);

                $db->prepare('UPDATE conversations SET owner_id = ? WHERE id = ?')->execute([$newOwnerId, $conversationId]);
            }

            $left = self::deleteMembers($conversationId, [$userId])[$userId];
            self::bumpInfoVersion($conversationId);

            $groupInfo = self::findInfo($conversationId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $left + ['groupInfo' => $groupInfo, 'newOwnerId' => $newOwnerId];
    }

    // ---- События ----

    /** groups / created — во все соединения создателя. */
    public static function publishCreated(array $conversation, array $groupInfo, int $ownerId): void
    {
        WebSocket::publish([$ownerId], 'groups', 'created',
            ['conversation' => $conversation, 'groupInfo' => $groupInfo], [$ownerId]);
    }

    /**
     * groups / membersAdded — добавленным со своей версией (Conversation они загружают через getBatch),
     * остальным участникам — только GroupInfo. Если никого не добавили — событий нет.
     */
    public static function publishMembersAdded(int $conversationId, array $result): void
    {
        $added = $result['addedUserIds'];
        if (!$added) {
            return;
        }

        $body = [
            'conversationId' => $conversationId,
            'addedUserIds'   => $added,
            'groupInfo'      => $result['groupInfo'],
        ];
        $others = array_diff(self::memberIdsOf($result['groupInfo']), $added);

        WebSocket::publishEach([
            ...array_map(
                fn($userId) => WebSocket::delivery([$userId], 'groups', 'membersAdded',
                    $body + ['changeVersion' => $result['changeVersions'][$userId]], $added),
                $added
            ),
            WebSocket::delivery($others, 'groups', 'membersAdded', $body + ['changeVersion' => null], $added),
        ]);
    }

    /** groups / membersRemoved — удалённым метку и общий счётчик, оставшимся — GroupInfo. */
    public static function publishMembersRemoved(int $conversationId, array $result): void
    {
        $removedIds = $result['removedUserIds'];
        if (!$removedIds) {
            return;
        }

        self::publishRemoval(
            'membersRemoved',
            ['conversationId' => $conversationId, 'removedUserIds' => $removedIds],
            $result['groupInfo'], $result['removed'], $removedIds
        );
    }

    /** groups / memberLeft — вышедшему метку и общий счётчик, оставшимся — GroupInfo (с новым владельцем). */
    public static function publishMemberLeft(int $conversationId, int $userId, array $result): void
    {
        self::publishRemoval(
            'memberLeft',
            ['conversationId' => $conversationId, 'leftUserId' => $userId],
            $result['groupInfo'],
            [$userId => ['removedConversation' => $result['removedConversation'], 'totalUnreadCount' => $result['totalUnreadCount']]],
            array_values(array_filter([$userId, $result['newOwnerId']]))
        );
    }

    /** groups / ownerChanged — всем участникам. */
    public static function publishOwnerChanged(int $conversationId, int $newOwnerId, array $groupInfo): void
    {
        WebSocket::publish(self::memberIdsOf($groupInfo), 'groups', 'ownerChanged', [
            'conversationId' => $conversationId,
            'newOwnerUserId' => $newOwnerId,
            'groupInfo'      => $groupInfo,
        ], [$newOwnerId]);
    }

    // ---- Внутреннее ----

    /**
     * Блокирует строку переписки и проверяет доступ $userId: CONVERSATION_NOT_FOUND — переписки нет
     * или пользователь не участник, CONVERSATION_NOT_GROUP — не группа, GROUP_FORBIDDEN — $ownerOnly
     * и пользователь не владелец. Возвращает строку переписки (owner_id, last_message_id) или null с $error.
     */
    private static function lock(int $conversationId, int $userId, bool $ownerOnly, ?int &$error): ?array
    {
        $error = null;

        $stmt = DB::get()->prepare('SELECT type, owner_id, last_message_id FROM conversations WHERE id = ? FOR UPDATE');
        $stmt->execute([$conversationId]);
        $group = $stmt->fetch();

        if (!$group || !Conversation::lockMember($conversationId, $userId)) {
            $error = ErrorCode::CONVERSATION_NOT_FOUND;
        } elseif ($group['type'] !== Conversation::TYPE_GROUP) {
            $error = ErrorCode::CONVERSATION_NOT_GROUP;
        } elseif ($ownerOnly && (int) $group['owner_id'] !== $userId) {
            $error = ErrorCode::GROUP_FORBIDDEN;
        }

        return $error === null ? $group : null;
    }

    /** Блокирует строки участников группы и возвращает их id по возрастанию. */
    private static function lockMemberIds(int $conversationId): array
    {
        $stmt = DB::get()->prepare(
            'SELECT user_id FROM conversation_members WHERE conversation_id = ? ORDER BY user_id FOR UPDATE'
        );
        $stmt->execute([$conversationId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Удаляет участников $userIds (под блокировкой переписки и их строк): версия растёт, непрочитанные группы
     * вычитаются из общего счётчика, вместо строки участника — метка удаления с новой версией.
     * Возвращает userId => [removedConversation, totalUnreadCount].
     */
    private static function deleteMembers(int $conversationId, array $userIds): array
    {
        $db = DB::get();
        sort($userIds);
        $in = self::placeholders($userIds);

        // Строки users — по возрастанию id до multi-table UPDATE (в нём ORDER BY недоступен) и до вставки меток
        $db->prepare("SELECT id FROM users WHERE id IN ($in) ORDER BY id FOR UPDATE")->execute($userIds);

        // GREATEST — сломанный счётчик не должен блокировать удаление
        $db->prepare(
            "UPDATE users u JOIN conversation_members m ON m.user_id = u.id AND m.conversation_id = ?
             SET u.change_version     = u.change_version + 1,
                 u.total_unread_count = GREATEST(CAST(u.total_unread_count AS SIGNED) - CAST(m.unread_count AS SIGNED), 0)
             WHERE u.id IN ($in)"
        )->execute([$conversationId, ...$userIds]);

        $db->prepare(
            "INSERT INTO conversation_removed_members (conversation_id, user_id, change_version)
             SELECT ?, id, change_version FROM users WHERE id IN ($in)"
        )->execute([$conversationId, ...$userIds]);

        $db->prepare("DELETE FROM conversation_members WHERE conversation_id = ? AND user_id IN ($in)")
            ->execute([$conversationId, ...$userIds]);

        $stmt = $db->prepare("SELECT id, total_unread_count, change_version FROM users WHERE id IN ($in) ORDER BY id");
        $stmt->execute($userIds);

        $removed = [];
        foreach ($stmt->fetchAll() as $row) {
            $removed[(int) $row['id']] = [
                'removedConversation' => Conversation::removedFromRow($conversationId, (int) $row['change_version']),
                'totalUnreadCount'    => (int) $row['total_unread_count'],
            ];
        }
        return $removed;
    }

    /** Общая рассылка удаления и выхода: затронутым — метка и счётчик, оставшимся — GroupInfo. */
    private static function publishRemoval(string $event, array $body, array $groupInfo, array $removed, array $userIds): void
    {
        WebSocket::publishEach([
            ...array_map(
                fn($userId) => WebSocket::delivery([$userId], 'groups', $event,
                    $body + ['groupInfo' => null] + $removed[$userId], $userIds),
                array_keys($removed)
            ),
            WebSocket::delivery(self::memberIdsOf($groupInfo), 'groups', $event,
                $body + ['groupInfo' => $groupInfo, 'removedConversation' => null, 'totalUnreadCount' => null], $userIds),
        ]);
    }

    private static function bumpInfoVersion(int $conversationId): void
    {
        DB::get()->prepare('UPDATE conversations SET group_info_version = group_info_version + 1 WHERE id = ?')
            ->execute([$conversationId]);
    }

    /** UserErrorInfo: пользователь, по которому действие не выполнено, и код ошибки. */
    private static function userError(int $userId, int $errorCode): array
    {
        return ['userId' => $userId, 'errorCode' => $errorCode];
    }

    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
