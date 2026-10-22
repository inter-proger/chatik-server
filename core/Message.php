<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Conversation.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/WebSocket.php';

class Message
{
    const TYPE_TEXT = 'text';

    /** Сколько сообщений помещается в один блок */
    const BLOCK_SIZE = 20;

    /** Максимальная длина текста сообщения в символах */
    const TEXT_MAX_LENGTH = 4096;

    /** Максимальная длина clientId */
    const CLIENT_ID_MAX_LENGTH = 64;

    /**
     * Сколько блоков отдаёт /messages/getBlockBatch за раз; столько же, не больше, — updatedBlockIds
     * в findListReceivedAfter(): если изменённых блоков меньше, клиент обновит их одним запросом
     */
    const BLOCK_BATCH_MAX_COUNT = 10;

    private static function select(): string
    {
        return 'SELECT id, conversation_id, block_id, sender_id, client_id, type, text, is_edited,
                       ' . DB::ms('created_at') . ' AS created_at, ' . DB::ms('updated_at') . ' AS updated_at
                FROM messages';
    }

    /**
     * Возвращает сущность сообщения по id или null, если не найдено.
     *
     * Формат: messageId, clientId, conversationId, blockId, senderId, type, text, isEdited, createdAt, updatedAt (мс)
     */
    public static function findById(int $id): ?array
    {
        $stmt = DB::get()->prepare(self::select() . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? self::fromRow($row) : null;
    }

    /** Сообщения блока — сначала новые (по убыванию id). */
    public static function findByBlock(int $blockId): array
    {
        return self::findByBlocks([$blockId]);
    }

    /** id переписки, к которой относится блок, или null, если блока нет. */
    public static function findBlockConversationId(int $blockId): ?int
    {
        $stmt = DB::get()->prepare('SELECT conversation_id FROM message_blocks WHERE id = ?');
        $stmt->execute([$blockId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Блоки по списку id (MessageBlock: blockId, conversationId, messages — сначала новые, updatedAt в мс или null).
     * Только блоки переписок, в которых состоит $viewerId; ключи — id блоков, порядок не гарантирован.
     */
    public static function findBlocksByIds(array $ids, int $viewerId): array
    {
        if (!$ids) {
            return [];
        }

        // STRAIGHT_JOIN — сначала блоки по PRIMARY, потом членство: иначе оптимизатор идёт от переписок
        // пользователя и перебирает все их блоки (оценивает ~25 блоков на переписку вместо тысяч)
        $stmt = DB::get()->prepare(
            'SELECT STRAIGHT_JOIN b.id, b.conversation_id, ' . DB::ms('b.updated_at') . ' AS updated_at
             FROM message_blocks b
             JOIN conversation_members m ON m.conversation_id = b.conversation_id AND m.user_id = ?
             WHERE b.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute([$viewerId, ...$ids]);
        $rows = $stmt->fetchAll();

        $messages = [];
        foreach (self::findByBlocks(array_map('intval', array_column($rows, 'id'))) as $message) {
            $messages[$message['blockId']][] = $message;
        }

        $blocks = [];
        foreach ($rows as $row) {
            $blocks[(int) $row['id']] = self::blockFromRow($row, $messages[(int) $row['id']] ?? []);
        }

        return $blocks;
    }

    /**
     * Сообщения переписки целыми блоками — от блока $fromBlockId (или самого нового) к старым,
     * пока не наберётся не меньше $minCount мест (messages_count) или блоки не кончатся.
     * Места занимают и удалённые сообщения, поэтому живых сообщений может быть меньше $minCount, вплоть до 0.
     *
     * Возвращает ['messages' => сначала новые, 'nextBlockId' => следующий (более старый) блок или null].
     */
    public static function findList(int $conversationId, ?int $fromBlockId, int $minCount): array
    {
        $params = [$conversationId];
        $sql = 'SELECT id, messages_count FROM message_blocks WHERE conversation_id = ?';
        if ($fromBlockId !== null) {
            $sql .= ' AND id <= ?';
            $params[] = $fromBlockId;
        }
        // По местам неполным бывает только последний блок (в нём хотя бы одно), остальные полные:
        // первый блок + ceil($minCount / BLOCK_SIZE) полных + ещё один, чтобы узнать следующий
        $sql .= ' ORDER BY id DESC LIMIT ' . ((int) ceil($minCount / self::BLOCK_SIZE) + 2);

        $stmt = DB::get()->prepare($sql);
        $stmt->execute($params);

        $blockIds    = [];
        $count       = 0;
        $nextBlockId = null;
        foreach ($stmt->fetchAll() as $block) {
            if ($count >= $minCount) {
                $nextBlockId = (int) $block['id'];
                break;
            }
            $blockIds[] = (int) $block['id'];
            $count += (int) $block['messages_count'];
        }

        return [
            'messages'    => self::findByBlocks($blockIds),
            'nextBlockId' => $nextBlockId,
        ];
    }

    /**
     * Первая страница findList() с фильтром для догоняющей синхронизации: в messages — только сообщения
     * с createdAt > $afterMs (мс). Возвращает ['messages', 'nextBlockId', 'hasNext'] и при hasNext = false —
     * 'updatedBlockIds': блоки, сообщения которых менялись после $afterMs, по убыванию id, не больше BLOCK_BATCH_MAX_COUNT.
     *
     * hasNext — есть ли новые сообщения и за пределами страницы (клиенту нужно сбросить кэш). Тогда все
     * сообщения страницы новые, и страница совпадает с первой страницей findList() без фильтра.
     * Опирается на то, что created_at растёт вместе с id внутри переписки (send() под блокировкой переписки).
     */
    public static function findListReceivedAfter(int $conversationId, int $afterMs, int $minCount): array
    {
        $list = self::findList($conversationId, null, $minCount);
        $messages = array_values(array_filter($list['messages'], fn($m) => $m['createdAt'] > $afterMs));

        $hasNext = false;
        // Сообщения страницы — по убыванию id: если новое самое старое, новые все
        if ($list['nextBlockId'] !== null && count($messages) === count($list['messages'])) {
            // Самое новое сообщение старше страницы — пустые блоки пропускаются
            $stmt = DB::get()->prepare(
                'SELECT ' . DB::ms('created_at') . ' FROM messages
                 WHERE conversation_id = ? AND block_id <= ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$conversationId, $list['nextBlockId']]);
            $createdAt = $stmt->fetchColumn();
            $hasNext = $createdAt !== false && (int) $createdAt > $afterMs;
        }

        $result = [
            'messages'    => $messages,
            'nextBlockId' => $hasNext ? $list['nextBlockId'] : null,
            'hasNext'     => $hasNext,
        ];

        if (!$hasNext) {
            $stmt = DB::get()->prepare(
                'SELECT id FROM message_blocks WHERE conversation_id = ? AND updated_at > ' . DB::fromMs() . '
                 ORDER BY id DESC LIMIT ' . self::BLOCK_BATCH_MAX_COUNT
            );
            $stmt->execute([$conversationId, $afterMs]);
            $result['updatedBlockIds'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        return $result;
    }

    /**
     * Добавляет сообщение в последний блок переписки (или в новый, если последний заполнен),
     * делает его последним сообщением переписки и поднимает её в списке (positionUpdatedAt = createdAt сообщения).
     * Возвращает сущность сообщения.
     *
     * $clientId — идентификатор от клиента (null — сгенерировать UUID). Если у отправителя уже есть
     * сообщение с таким clientId, новое не создаётся: возвращается существующее (оно может быть
     * из другой переписки), $isNew = false.
     *
     * Обновляет прочтение: у отправителя указатель встаёт на сообщение, у остальных счётчики растут на 1,
     * версия растёт у всех участников. $readInfos — ReadInfo участников (userId => ReadInfo) на версии
     * этого изменения; при повторе — null.
     *
     * null — отправитель не участник переписки: участие проверяется под блокировкой переписки, потому что
     * из группы могли удалить после проверки в обработчике. Ничего не меняется.
     */
    public static function send(
        int $conversationId, int $senderId, string $text, ?string $clientId = null, ?bool &$isNew = null,
        ?array &$readInfos = null
    ): ?array {
        $readInfos = null;
        $clientId ??= self::uuid();

        $db = DB::get();
        $db->beginTransaction();
        try {
            // Блокировка переписки сериализует параллельные отправки — иначе блок мог бы переполниться,
            // а повторы с одним clientId — создать дубль
            $stmt = $db->prepare('SELECT id FROM conversations WHERE id = ? FOR UPDATE');
            $stmt->execute([$conversationId]);

            // Строка участника отправителя (шаг 2 порядка блокировок) — и проверка участия под блокировкой,
            // и его непрочитанные для updateReadState(). До поиска по clientId: удалённый не должен получить
            // через повтор и своё старое сообщение
            $stmt = $db->prepare(
                'SELECT unread_count FROM conversation_members WHERE conversation_id = ? AND user_id = ? FOR UPDATE'
            );
            $stmt->execute([$conversationId, $senderId]);
            $senderUnread = $stmt->fetchColumn();
            if ($senderUnread === false) {
                $db->rollBack();
                return null;
            }

            $stmt = $db->prepare('SELECT id FROM messages WHERE sender_id = ? AND client_id = ?');
            $stmt->execute([$senderId, $clientId]);
            $existingId = $stmt->fetchColumn();
            if ($existingId !== false) {
                $db->commit();
                $isNew = false;
                return self::findById((int) $existingId);
            }

            $stmt = $db->prepare(
                'SELECT id, messages_count FROM message_blocks WHERE conversation_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$conversationId]);
            $block = $stmt->fetch();

            if ($block && (int) $block['messages_count'] < self::BLOCK_SIZE) {
                $blockId = (int) $block['id'];
            } else {
                $db->prepare('INSERT INTO message_blocks (conversation_id) VALUES (?)')->execute([$conversationId]);
                $blockId = (int) $db->lastInsertId();
            }

            $stmt = $db->prepare(
                'INSERT INTO messages (conversation_id, block_id, sender_id, client_id, type, text) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$conversationId, $blockId, $senderId, $clientId, self::TYPE_TEXT, $text]);
            $id = (int) $db->lastInsertId();

            $db->prepare('UPDATE message_blocks SET messages_count = messages_count + 1 WHERE id = ?')->execute([$blockId]);

            // Время — из строки сообщения, чтобы positionUpdatedAt совпадал с createdAt до миллисекунды
            $db->prepare(
                'UPDATE conversations c JOIN messages m ON m.id = ?
                 SET c.last_message_id = m.id, c.position_updated_at = m.created_at
                 WHERE c.id = ?'
            )->execute([$id, $conversationId]);

            self::updateReadState($conversationId, $senderId, $id, (int) $senderUnread);
            $readInfos = Conversation::findReadInfos($conversationId);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $isNew = true;
        return self::findById($id);
    }

    /**
     * Прочтение при новом сообщении $messageId (в транзакции send(), под блокировкой переписки):
     * у отправителя указатель встаёт на сообщение и его непрочитанные в переписке вычитаются из общего счётчика,
     * у остальных счётчики растут на 1; версия растёт у всех, строки участников получают новую версию.
     * $senderUnread — непрочитанные отправителя в переписке, прочитанные под блокировкой его строки в send():
     * отдельно, а не в UPDATE — в multi-table UPDATE MySQL не гарантирует порядок присваиваний.
     */
    private static function updateReadState(int $conversationId, int $senderId, int $messageId, int $senderUnread): void
    {
        $db = DB::get();
        $delta = $senderUnread;

        $memberIds = Conversation::findMemberIds($conversationId);
        if (!$memberIds) {
            return;
        }

        // Все строки users одним запросом по возрастанию id — параллельные отправки в разные переписки
        // не сцепляются в дедлок. GREATEST — сломанный счётчик не должен блокировать отправку
        $db->prepare(
            'UPDATE users SET change_version = change_version + 1,
                              total_unread_count = GREATEST(CAST(total_unread_count AS SIGNED) + IF(id = ?, CAST(? AS SIGNED), 1), 0)
             WHERE id IN (' . implode(',', array_fill(0, count($memberIds), '?')) . ') ORDER BY id'
        )->execute([$senderId, -$delta, ...$memberIds]);

        $db->prepare(
            'UPDATE conversation_members m JOIN users u ON u.id = m.user_id
             SET m.change_version       = u.change_version,
                 m.last_read_message_id = IF(m.user_id = ?, ?, m.last_read_message_id),
                 m.unread_count         = IF(m.user_id = ?, 0, m.unread_count + 1)
             WHERE m.conversation_id = ?'
        )->execute([$senderId, $messageId, $senderId, $conversationId]);
    }

    /**
     * Меняет текст сообщения, помечает его отредактированным и возвращает обновлённую сущность.
     * Время изменения блока сообщения становится равным updatedAt сообщения.
     * Если сообщение — последнее в переписке, её updatedInfoAt становится равным updatedAt сообщения
     * (позиция не меняется), а версия участников растёт; $isLast — было ли сообщение последним,
     * $changeVersions — новые версии участников (userId => changeVersion) или null, если не последнее.
     *
     * null — автор сообщения больше не участник переписки (проверяется под блокировкой, как в send()):
     * править сообщение может только автор, поэтому проверяется именно он. Ничего не меняется.
     */
    public static function edit(int $id, string $text, ?bool &$isLast = null, ?array &$changeVersions = null): ?array
    {
        $changeVersions = null;

        $db = DB::get();
        $db->beginTransaction();
        try {
            // Блокировка переписки — как в send(): «последнее ли сообщение» не изменится до коммита
            $stmt = $db->prepare(
                'SELECT c.id, c.last_message_id, m.sender_id FROM messages m JOIN conversations c ON c.id = m.conversation_id
                 WHERE m.id = ? FOR UPDATE OF c'
            );
            $stmt->execute([$id]);
            $conversation = $stmt->fetch();

            if (!Conversation::lockMember((int) $conversation['id'], (int) $conversation['sender_id'])) {
                $db->rollBack();
                return null;
            }

            $isLast = (int) $conversation['last_message_id'] === $id;

            $db->prepare('UPDATE messages SET text = ?, is_edited = 1, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')
                ->execute([$text, $id]);

            $db->prepare(
                'UPDATE message_blocks b JOIN messages m ON m.block_id = b.id SET b.updated_at = m.updated_at WHERE m.id = ?'
            )->execute([$id]);

            if ($isLast) {
                $db->prepare(
                    'UPDATE conversations c JOIN messages m ON m.id = c.last_message_id
                     SET c.updated_info_at = m.updated_at
                     WHERE c.last_message_id = ?'
                )->execute([$id]);

                $changeVersions = Conversation::bumpChangeVersion((int) $conversation['id']);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return self::findById($id);
    }

    /**
     * Приводит текст сообщения из запроса к виду для сохранения (см. Request::normalizeText()).
     * null — если текст не строка, пустой или длиннее TEXT_MAX_LENGTH.
     */
    public static function normalizeText(mixed $text): ?string
    {
        $text = Request::normalizeText($text, self::TEXT_MAX_LENGTH);

        return $text === '' ? null : $text;
    }

    /**
     * Проверяет clientId из запроса: строка из латинских букв, цифр, «-» и «_»,
     * от 1 до CLIENT_ID_MAX_LENGTH символов. null — если не подходит.
     */
    public static function normalizeClientId(mixed $clientId): ?string
    {
        if (!is_string($clientId)
            || !preg_match('/^[A-Za-z0-9_-]{1,' . self::CLIENT_ID_MAX_LENGTH . '}$/D', $clientId)) {
            return null;
        }

        return $clientId;
    }

    public static function fromRow(array $row): array
    {
        return [
            'messageId'      => (int) $row['id'],
            'clientId'       => $row['client_id'],
            'conversationId' => (int) $row['conversation_id'],
            'blockId'        => (int) $row['block_id'],
            'senderId'       => (int) $row['sender_id'],
            'type'           => $row['type'],
            'text'           => $row['text'],
            'isEdited'       => (bool) $row['is_edited'],
            'createdAt'      => (int) $row['created_at'],
            'updatedAt'      => (int) $row['updated_at'],
        ];
    }

    /** MessageBlock из строки message_blocks (id, conversation_id, updated_at в мс или NULL) и сообщений блока. */
    public static function blockFromRow(array $row, array $messages): array
    {
        return [
            'blockId'        => (int) $row['id'],
            'conversationId' => (int) $row['conversation_id'],
            'messages'       => $messages,
            'updatedAt'      => $row['updated_at'] !== null ? (int) $row['updated_at'] : null,
        ];
    }

    /**
     * LastMessage — краткое представление последнего сообщения переписки для списка чатов:
     * messageId, senderId, type, text, updatedAt (мс). Строка — колонки id, sender_id, type, text, updated_at (мс).
     */
    public static function lastMessageFromRow(array $row): array
    {
        return [
            'messageId' => (int) $row['id'],
            'senderId'  => (int) $row['sender_id'],
            'type'      => $row['type'],
            'text'      => $row['text'],
            'updatedAt' => (int) $row['updated_at'],
        ];
    }

    /** LastMessage из сущности сообщения. */
    public static function toLastMessage(array $message): array
    {
        return [
            'messageId' => $message['messageId'],
            'senderId'  => $message['senderId'],
            'type'      => $message['type'],
            'text'      => $message['text'],
            'updatedAt' => $message['updatedAt'],
        ];
    }

    /**
     * Рассылает messages/received о новом сообщении всем участникам, включая устройства отправителя.
     * readInfo зависит от получателя — каждому своя доставка; $readInfos — из send().
     */
    public static function publishReceived(array $message, array $readInfos): void
    {
        $body = ['message' => $message, 'lastMessage' => self::toLastMessage($message)];

        WebSocket::publishEach(array_map(
            fn($memberId) => WebSocket::delivery(
                [$memberId], 'messages', 'received', $body + ['readInfo' => $readInfos[$memberId]], [$message['senderId']]
            ),
            array_keys($readInfos)
        ));
    }

    private static function findByBlocks(array $blockIds): array
    {
        if (!$blockIds) {
            return [];
        }

        $stmt = DB::get()->prepare(
            self::select() . ' WHERE block_id IN (' . implode(',', array_fill(0, count($blockIds), '?')) . ')
             ORDER BY id DESC'
        );
        $stmt->execute($blockIds);

        return array_map([self::class, 'fromRow'], $stmt->fetchAll());
    }

    /** UUID v4 */
    private static function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
