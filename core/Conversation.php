<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/File.php';
require_once __DIR__ . '/Message.php';

class Conversation
{
    const TYPE_SINGLE = 'single';
    const TYPE_GROUP  = 'group';

    /**
     * Колонки выборки переписки: сама переписка, строка участника-смотрящего (m — поля прочтения),
     * файл аватара (префикс f_) и последнее сообщение (префикс lm_).
     */
    private static function columns(): string
    {
        return 'c.id, c.type, c.name,
                ' . DB::ms('c.position_updated_at') . ' AS position_updated_at,
                ' . DB::ms('c.updated_info_at') . ' AS updated_info_at,
                m.last_read_message_id, m.unread_count, m.change_version,
                f.id AS f_id, f.type AS f_type, f.path AS f_path, f.size AS f_size,
                f.filename AS f_filename, f.extension AS f_extension, f.data AS f_data,
                lm.id AS lm_id, lm.type AS lm_type, lm.text AS lm_text,
                ' . DB::ms('lm.updated_at') . ' AS lm_updated_at';
    }

    /** JOIN аватара и последнего сообщения к c — общий для всех выборок. */
    private static function joins(): string
    {
        return 'LEFT JOIN files f ON f.id = c.avatar_file_id
                LEFT JOIN messages lm ON lm.id = c.last_message_id';
    }

    /**
     * Выборка переписок вместе со строкой участника-смотрящего: первый параметр запроса — viewerId.
     * $membersOnly = false — переписки и без смотрящего среди участников (поля прочтения тогда 0).
     */
    private static function select(bool $membersOnly = true): string
    {
        return 'SELECT ' . self::columns() . '
                FROM conversations c
                ' . ($membersOnly ? 'JOIN' : 'LEFT JOIN') . ' conversation_members m ON m.conversation_id = c.id AND m.user_id = ?
                ' . self::joins();
    }

    /**
     * Возвращает сущность переписки по id или null, если не найдена.
     * $viewerId — пользователь, для которого собирается сущность (от него зависят opponentUserId и поля прочтения).
     *
     * Формат: conversationId, type, memberIds, name, avatar (сущность File или null),
     * opponentUserId (id собеседника для single, иначе null), lastMessage (LastMessage или null),
     * positionUpdatedAt, updatedInfoAt (мс), unreadCount, lastReadMessageId, changeVersion
     */
    public static function findById(int $id, int $viewerId): ?array
    {
        $stmt = DB::get()->prepare(self::select(false) . ' WHERE c.id = ?');
        $stmt->execute([$viewerId, $id]);
        $row = $stmt->fetch();

        return $row ? self::fromRows([$row], $viewerId)[0] : null;
    }

    /**
     * Переписки из $ids, в которых состоит $viewerId, — массив conversationId => сущность.
     * Чужие и несуществующие в результат не попадают.
     */
    public static function findByIds(array $ids, int $viewerId): array
    {
        if (!$ids) {
            return [];
        }

        $ids  = array_values(array_unique(array_map('intval', $ids)));
        $stmt = DB::get()->prepare(
            self::select() . ' WHERE c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute([$viewerId, ...$ids]);

        $conversations = [];
        foreach (self::fromRows($stmt->fetchAll(), $viewerId) as $conversation) {
            $conversations[$conversation['conversationId']] = $conversation;
        }
        return $conversations;
    }

    /**
     * Переписки, в которых состоит пользователь, — сначала поднятые последними
     * (по убыванию positionUpdatedAt, при равенстве — по убыванию id).
     *
     * @param int|null $positionUpdatedAfter только переписки с positionUpdatedAt строго больше (мс)
     */
    public static function findListByMember(int $userId, int $offset, int $count, ?int $positionUpdatedAfter = null): array
    {
        $params = [$userId];
        $sql    = self::select();
        if ($positionUpdatedAfter !== null) {
            $sql .= ' WHERE c.position_updated_at > ' . DB::fromMs();
            $params[] = $positionUpdatedAfter;
        }
        $sql .= ' ORDER BY c.position_updated_at DESC, c.id DESC
             LIMIT ' . $count . ' OFFSET ' . $offset;

        $stmt = DB::get()->prepare($sql);
        $stmt->execute($params);

        return self::fromRows($stmt->fetchAll(), $userId);
    }

    /**
     * Переписки пользователя с changeVersion строго больше $changedAfter — по возрастанию changeVersion:
     * следующая страница запрашивается версией последней переписки, и изменившиеся во время листания
     * уходят в конец выдачи. Ненулевые версии строк одного пользователя не повторяются.
     */
    public static function findChangedByMember(int $userId, int $changedAfter, int $count): array
    {
        // STRAIGHT_JOIN — от строк участника по ключу user_change_version: иначе оптимизатор может
        // начать с conversations и сортировать все переписки пользователя
        $stmt = DB::get()->prepare(
            'SELECT STRAIGHT_JOIN ' . self::columns() . '
             FROM conversation_members m
             JOIN conversations c ON c.id = m.conversation_id
             ' . self::joins() . '
             WHERE m.user_id = ? AND m.change_version > ?
             ORDER BY m.change_version
             LIMIT ' . $count
        );
        $stmt->execute([$userId, $changedAfter]);

        return self::fromRows($stmt->fetchAll(), $userId);
    }

    /** Общий счётчик пользователя: ['totalUnreadCount', 'changeVersion'] (0 и 0 для несуществующего). */
    public static function findTotalUnreadCount(int $userId): array
    {
        $stmt = DB::get()->prepare('SELECT total_unread_count, change_version FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return [
            'totalUnreadCount' => (int) ($row['total_unread_count'] ?? 0),
            'changeVersion'    => (int) ($row['change_version'] ?? 0),
        ];
    }

    /**
     * ReadInfo участников переписки (только $userIds, если передан) — массив userId => ReadInfo
     * по возрастанию userId. Поля каждого снимаются одним запросом на версии users.change_version;
     * в транзакции изменения (строки заблокированы) это ровно версия изменения.
     */
    public static function findReadInfos(int $conversationId, ?array $userIds = null): array
    {
        $params = [$conversationId];
        $sql = 'SELECT m.conversation_id, m.user_id, m.last_read_message_id, m.unread_count,
                       u.total_unread_count, u.change_version
                FROM conversation_members m
                JOIN users u ON u.id = m.user_id
                WHERE m.conversation_id = ?';
        if ($userIds !== null) {
            if (!$userIds) {
                return [];
            }
            $sql .= ' AND m.user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
            array_push($params, ...$userIds);
        }
        $stmt = DB::get()->prepare($sql . ' ORDER BY m.user_id');
        $stmt->execute($params);

        $readInfos = [];
        foreach ($stmt->fetchAll() as $row) {
            $readInfos[(int) $row['user_id']] = self::readInfoFromRow($row);
        }
        return $readInfos;
    }

    /**
     * Отмечает прочитанными сообщения переписки до $messageId включительно и возвращает ReadInfo пользователя.
     * Сообщение должно быть из этой переписки, пользователь — её участником (проверяет вызывающий).
     * Указатель только растёт: $messageId не новее текущего — ничего не меняется, $changed = false.
     */
    public static function markRead(int $conversationId, int $userId, int $messageId, ?bool &$changed = null): array
    {
        $db = DB::get();
        $db->beginTransaction();
        try {
            // Порядок блокировок, как в Message::send(): переписка → строка участника → users
            $stmt = $db->prepare('SELECT last_message_id FROM conversations WHERE id = ? FOR UPDATE');
            $stmt->execute([$conversationId]);
            $lastMessageId = (int) $stmt->fetchColumn();

            $stmt = $db->prepare(
                'SELECT last_read_message_id, unread_count FROM conversation_members
                 WHERE conversation_id = ? AND user_id = ? FOR UPDATE'
            );
            $stmt->execute([$conversationId, $userId]);
            $member = $stmt->fetch();

            $changed = $messageId > (int) $member['last_read_message_id'];
            if ($changed) {
                // Свои сообщения не считаются — как в recountUnread()
                $newUnread = 0;
                if ($messageId !== $lastMessageId) {
                    $stmt = $db->prepare(
                        'SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND id > ? AND sender_id <> ?'
                    );
                    $stmt->execute([$conversationId, $messageId, $userId]);
                    $newUnread = (int) $stmt->fetchColumn();
                }

                // GREATEST — сломанный счётчик не должен блокировать прочтение (UNSIGNED ниже нуля — ошибка)
                $db->prepare(
                    'UPDATE users SET change_version = change_version + 1,
                                      total_unread_count = GREATEST(CAST(total_unread_count AS SIGNED) - CAST(? AS SIGNED), 0)
                     WHERE id = ?'
                )->execute([(int) $member['unread_count'] - $newUnread, $userId]);

                $db->prepare(
                    'UPDATE conversation_members m JOIN users u ON u.id = m.user_id
                     SET m.last_read_message_id = ?, m.unread_count = ?, m.change_version = u.change_version
                     WHERE m.conversation_id = ? AND m.user_id = ?'
                )->execute([$messageId, $newUnread, $conversationId, $userId]);
            }

            $readInfo = self::findReadInfos($conversationId, [$userId])[$userId];
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $readInfo;
    }

    /**
     * Пересчитывает счётчики непрочитанных из указателей прочтения — для проверки инварианта
     * total_unread_count = SUM(unread_count) в тестах и ручного исправления. Версии не меняет.
     * $userId — только для этого пользователя.
     */
    public static function recountUnread(?int $userId = null): void
    {
        $db = DB::get();
        $where = $userId !== null ? ' WHERE m.user_id = ?' : '';
        $params = $userId !== null ? [$userId] : [];

        $db->prepare(
            'UPDATE conversation_members m
             SET m.unread_count = (SELECT COUNT(*) FROM messages x
                                   WHERE x.conversation_id = m.conversation_id AND x.id > m.last_read_message_id
                                     AND x.sender_id <> m.user_id)' . $where
        )->execute($params);

        $db->prepare(
            'UPDATE users u
             SET u.total_unread_count = (SELECT COALESCE(SUM(m.unread_count), 0) FROM conversation_members m WHERE m.user_id = u.id)'
            . ($userId !== null ? ' WHERE u.id = ?' : '')
        )->execute($params);
    }

    /**
     * Увеличивает версию всех участников переписки — при любом изменении её информации
     * (всего, что двигает updated_info_at). Вызывается в транзакции изменения, под блокировкой переписки.
     * Возвращает новые версии участников: userId => changeVersion — их несёт событие изменения.
     */
    public static function bumpChangeVersion(int $conversationId): array
    {
        $db = DB::get();

        $memberIds = self::findMemberIds($conversationId);
        if (!$memberIds) {
            return [];
        }

        // Строки users — по возрастанию id, как в Message::send(): иначе параллельные изменения сцепились бы в дедлок
        $db->prepare(
            'UPDATE users SET change_version = change_version + 1
             WHERE id IN (' . implode(',', array_fill(0, count($memberIds), '?')) . ') ORDER BY id'
        )->execute($memberIds);

        $db->prepare(
            'UPDATE conversation_members m JOIN users u ON u.id = m.user_id
             SET m.change_version = u.change_version
             WHERE m.conversation_id = ?'
        )->execute([$conversationId]);

        $stmt = $db->prepare('SELECT user_id, change_version FROM conversation_members WHERE conversation_id = ? ORDER BY user_id');
        $stmt->execute([$conversationId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Указатели прочтения участников переписки, кроме $exceptUserId, — массив MemberReadMeta
     * (userId, lastReadMessageId) по возрастанию userId.
     */
    public static function findMembersReadMeta(int $conversationId, int $exceptUserId): array
    {
        $stmt = DB::get()->prepare(
            'SELECT user_id, last_read_message_id FROM conversation_members
             WHERE conversation_id = ? AND user_id <> ? ORDER BY user_id'
        );
        $stmt->execute([$conversationId, $exceptUserId]);

        return array_map(fn($row) => [
            'userId'            => (int) $row['user_id'],
            'lastReadMessageId' => (int) $row['last_read_message_id'],
        ], $stmt->fetchAll());
    }

    /** id участников переписки по возрастанию; для несуществующей переписки — пустой массив. */
    public static function findMemberIds(int $conversationId): array
    {
        $stmt = DB::get()->prepare('SELECT user_id FROM conversation_members WHERE conversation_id = ? ORDER BY user_id');
        $stmt->execute([$conversationId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Состоит ли пользователь в переписке. Для несуществующей переписки — false. */
    public static function isMember(int $conversationId, int $userId): bool
    {
        $stmt = DB::get()->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$conversationId, $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Возвращает личную переписку между двумя пользователями,
     * создавая её, если её ещё нет. $created — создана ли переписка этим вызовом.
     */
    public static function findOrCreateSingle(int $userId, int $otherUserId, ?bool &$created = null): array
    {
        $db  = DB::get();
        $key = self::singleKey($userId, $otherUserId);

        $created = false;
        $id = self::findIdBySingleKey($key);

        if ($id === null) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('INSERT INTO conversations (type, single_key) VALUES (?, ?)');
                $stmt->execute([self::TYPE_SINGLE, $key]);
                $id = (int) $db->lastInsertId();

                $stmt = $db->prepare(
                    'INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?), (?, ?)'
                );
                $stmt->execute([$id, $userId, $id, $otherUserId]);

                // Создание — изменение переписок обоих: строки участников сразу получают новую версию
                self::bumpChangeVersion($id);

                $db->commit();
                $created = true;
            } catch (PDOException $e) {
                $db->rollBack();

                // Параллельный запрос успел создать эту же переписку — отдаём её
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
                $id = self::findIdBySingleKey($key);
            }
        }

        return self::findById($id, $userId);
    }

    /**
     * Собирает сущности переписок из строк запроса (см. select()): участники
     * догружаются одним запросом на всю пачку.
     */
    private static function fromRows(array $rows, int $viewerId): array
    {
        if (!$rows) {
            return [];
        }

        $ids  = array_map(fn($row) => (int) $row['id'], $rows);
        $stmt = DB::get()->prepare(
            'SELECT conversation_id, user_id FROM conversation_members
             WHERE conversation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY user_id'
        );
        $stmt->execute($ids);

        $memberIds = array_fill_keys($ids, []);
        foreach ($stmt->fetchAll() as $m) {
            $memberIds[(int) $m['conversation_id']][] = (int) $m['user_id'];
        }

        // Собеседник в личной переписке — участник, отличный от $viewerId
        $opponentIds = [];
        foreach ($rows as $row) {
            if ($row['type'] === self::TYPE_SINGLE) {
                $others = array_values(array_diff($memberIds[(int) $row['id']], [$viewerId]));
                if ($others) {
                    $opponentIds[(int) $row['id']] = $others[0];
                }
            }
        }

        return array_map(function ($row) use ($memberIds, $opponentIds) {
            $id = (int) $row['id'];
            return self::fromRow($row, $memberIds[$id], $opponentIds[$id] ?? null);
        }, $rows);
    }

    /**
     * Собирает сущность переписки из строки запроса. Колонки аватара ожидаются с префиксом f_,
     * последнего сообщения — с префиксом lm_ (см. select()); если f_id / lm_id пустой — avatar / lastMessage = null.
     * Поля прочтения — из строки участника-смотрящего (last_read_message_id, unread_count, change_version;
     * NULL, если смотрящий не участник, — 0).
     */
    public static function fromRow(array $row, array $memberIds, ?int $opponentUserId): array
    {
        $avatar = null;
        if (!empty($row['f_id'])) {
            $avatar = File::fromRow([
                'id'        => $row['f_id'],
                'type'      => $row['f_type'],
                'path'      => $row['f_path'],
                'size'      => $row['f_size'],
                'filename'  => $row['f_filename'],
                'extension' => $row['f_extension'],
                'data'      => $row['f_data'],
            ]);
        }

        $lastMessage = null;
        if (!empty($row['lm_id'])) {
            $lastMessage = Message::lastMessageFromRow([
                'id'         => $row['lm_id'],
                'type'       => $row['lm_type'],
                'text'       => $row['lm_text'],
                'updated_at' => $row['lm_updated_at'],
            ]);
        }

        return [
            'conversationId'    => (int) $row['id'],
            'type'              => $row['type'],
            'memberIds'         => $memberIds,
            'name'              => $row['name'],
            'avatar'            => $avatar,
            'opponentUserId'    => $opponentUserId,
            'lastMessage'       => $lastMessage,
            'positionUpdatedAt' => (int) $row['position_updated_at'],
            'updatedInfoAt'     => (int) $row['updated_info_at'],
            'unreadCount'       => (int) $row['unread_count'],
            'lastReadMessageId' => (int) $row['last_read_message_id'],
            'changeVersion'     => (int) $row['change_version'],
        ];
    }

    /**
     * ReadInfo — состояние прочтения переписки для пользователя и его общий счётчик на одной версии:
     * conversationId, lastReadMessageId, unreadCount, totalUnreadCount, changeVersion.
     * Строка — колонки conversation_id, last_read_message_id, unread_count, total_unread_count, change_version.
     */
    public static function readInfoFromRow(array $row): array
    {
        return [
            'conversationId'    => (int) $row['conversation_id'],
            'lastReadMessageId' => (int) $row['last_read_message_id'],
            'unreadCount'       => (int) $row['unread_count'],
            'totalUnreadCount'  => (int) $row['total_unread_count'],
            'changeVersion'     => (int) $row['change_version'],
        ];
    }

    private static function findIdBySingleKey(string $key): ?int
    {
        $stmt = DB::get()->prepare('SELECT id FROM conversations WHERE single_key = ?');
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** Ключ уникальности личной переписки — не зависит от порядка пользователей. */
    private static function singleKey(int $a, int $b): string
    {
        return min($a, $b) . '_' . max($a, $b);
    }
}
