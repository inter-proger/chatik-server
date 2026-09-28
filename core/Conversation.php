<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/File.php';
require_once __DIR__ . '/User.php';

class Conversation
{
    const TYPE_SINGLE = 'single';
    const TYPE_GROUP  = 'group';

    /** Выборка переписки вместе с файлом аватара (колонки файла с префиксом f_) */
    private const SELECT =
        'SELECT c.id, c.type, c.name,
                f.id AS f_id, f.type AS f_type, f.path AS f_path, f.size AS f_size,
                f.filename AS f_filename, f.extension AS f_extension, f.data AS f_data
         FROM conversations c
         LEFT JOIN files f ON f.id = c.avatar_file_id';

    /**
     * Возвращает сущность переписки по id или null, если не найдена.
     * $viewerId — пользователь, для которого собирается сущность (от него зависит opponentUser).
     *
     * Формат: conversationId, type, memberIds, name, avatar (сущность File или null),
     * opponentUser (сущность User собеседника для single, иначе null)
     */
    public static function findById(int $id, int $viewerId): ?array
    {
        $stmt = DB::get()->prepare(self::SELECT . ' WHERE c.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? self::fromRows([$row], $viewerId)[0] : null;
    }

    /**
     * Переписки, в которых состоит пользователь, — сначала новые (по убыванию id).
     */
    public static function findListByMember(int $userId, int $offset, int $count): array
    {
        $stmt = DB::get()->prepare(
            self::SELECT . '
             JOIN conversation_members m ON m.conversation_id = c.id AND m.user_id = ?
             ORDER BY c.id DESC
             LIMIT ' . $count . ' OFFSET ' . $offset
        );
        $stmt->execute([$userId]);

        return self::fromRows($stmt->fetchAll(), $userId);
    }

    /**
     * Возвращает личную переписку между двумя пользователями,
     * создавая её, если её ещё нет.
     */
    public static function findOrCreateSingle(int $userId, int $otherUserId): array
    {
        $db  = DB::get();
        $key = self::singleKey($userId, $otherUserId);

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

                $db->commit();
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
     * Собирает сущности переписок из строк запроса (см. SELECT): участники и собеседники
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
        $users = User::findByIds($opponentIds);

        return array_map(function ($row) use ($memberIds, $opponentIds, $users) {
            $id = (int) $row['id'];
            return self::fromRow($row, $memberIds[$id], $users[$opponentIds[$id] ?? 0] ?? null);
        }, $rows);
    }

    /**
     * Собирает сущность переписки из строки запроса. Колонки аватара ожидаются
     * с префиксом f_ (см. SELECT); если f_id пустой — avatar = null.
     */
    public static function fromRow(array $row, array $memberIds, ?array $opponentUser): array
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

        return [
            'conversationId' => (int) $row['id'],
            'type'           => $row['type'],
            'memberIds'      => $memberIds,
            'name'           => $row['name'],
            'avatar'         => $avatar,
            'opponentUser'   => $opponentUser,
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
