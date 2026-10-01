<?php

require_once __DIR__ . '/DB.php';

class Message
{
    const TYPE_TEXT = 'text';

    /** Сколько сообщений помещается в один блок */
    const BLOCK_SIZE = 20;

    /** Максимальная длина текста сообщения в символах */
    const TEXT_MAX_LENGTH = 4096;

    private const SELECT =
        'SELECT id, conversation_id, block_id, sender_id, type, text, is_edited,
                UNIX_TIMESTAMP(created_at) AS created_at, UNIX_TIMESTAMP(updated_at) AS updated_at
         FROM messages';

    /**
     * Возвращает сущность сообщения по id или null, если не найдено.
     *
     * Формат: messageId, conversationId, blockId, senderId, type, text, isEdited, createdAt, updatedAt
     */
    public static function findById(int $id): ?array
    {
        $stmt = DB::get()->prepare(self::SELECT . ' WHERE id = ?');
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
     * Сообщения переписки целыми блоками — от блока $fromBlockId (или самого нового) к старым,
     * пока не наберётся не меньше $minCount сообщений или блоки не кончатся.
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
        // Пустых блоков не бывает, так что $minCount + 1 блоков точно хватит, чтобы узнать следующий
        $sql .= ' ORDER BY id DESC LIMIT ' . ($minCount + 1);

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
     * Добавляет сообщение в последний блок переписки (или в новый, если последний заполнен)
     * и возвращает его сущность.
     */
    public static function send(int $conversationId, int $senderId, string $text): array
    {
        $db = DB::get();
        $db->beginTransaction();
        try {
            // Блокировка переписки сериализует параллельные отправки — иначе блок мог бы переполниться
            $stmt = $db->prepare('SELECT id FROM conversations WHERE id = ? FOR UPDATE');
            $stmt->execute([$conversationId]);

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
                'INSERT INTO messages (conversation_id, block_id, sender_id, type, text) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$conversationId, $blockId, $senderId, self::TYPE_TEXT, $text]);
            $id = (int) $db->lastInsertId();

            $db->prepare('UPDATE message_blocks SET messages_count = messages_count + 1 WHERE id = ?')->execute([$blockId]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return self::findById($id);
    }

    /** Меняет текст сообщения, помечает его отредактированным и возвращает обновлённую сущность. */
    public static function edit(int $id, string $text): array
    {
        $stmt = DB::get()->prepare(
            'UPDATE messages SET text = ?, is_edited = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$text, $id]);

        return self::findById($id);
    }

    /**
     * Приводит текст из запроса к виду для сохранения: невалидные UTF-8 байты заменяются,
     * пробелы по краям обрезаются. null — если текст не строка, пустой или длиннее TEXT_MAX_LENGTH.
     */
    public static function normalizeText(mixed $text): ?string
    {
        if (!is_string($text)) {
            return null;
        }

        $text = trim(mb_scrub($text, 'UTF-8'));
        if ($text === '' || mb_strlen($text) > self::TEXT_MAX_LENGTH) {
            return null;
        }

        return $text;
    }

    public static function fromRow(array $row): array
    {
        return [
            'messageId'      => (int) $row['id'],
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

    private static function findByBlocks(array $blockIds): array
    {
        if (!$blockIds) {
            return [];
        }

        $stmt = DB::get()->prepare(
            self::SELECT . ' WHERE block_id IN (' . implode(',', array_fill(0, count($blockIds), '?')) . ')
             ORDER BY id DESC'
        );
        $stmt->execute($blockIds);

        return array_map([self::class, 'fromRow'], $stmt->fetchAll());
    }
}
