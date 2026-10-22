<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/File.php';

class User
{
    /** Максимальная длина имени — по колонкам users.name и conversations.name (название группы) */
    const NAME_MAX_LENGTH = 128;

    /** Выборка пользователя вместе с файлом аватара (колонки файла с префиксом f_) */
    private const SELECT =
        'SELECT u.id, u.login, u.name,
                f.id AS f_id, f.type AS f_type, f.path AS f_path, f.size AS f_size,
                f.filename AS f_filename, f.extension AS f_extension, f.data AS f_data
         FROM users u
         LEFT JOIN files f ON f.id = u.avatar_file_id';

    /**
     * Возвращает объект User по id или null, если пользователь не найден.
     *
     * Формат: userId, login, name, avatar (сущность File или null)
     */
    public static function findById(int $id): ?array
    {
        $stmt = DB::get()->prepare(self::SELECT . ' WHERE u.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? self::fromRow($row) : null;
    }

    /**
     * Возвращает объекты User по списку id: [userId => User]. Ненайденные id пропускаются.
     */
    public static function findByIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $ids  = array_values(array_unique(array_map('intval', $ids)));
        $stmt = DB::get()->prepare(
            self::SELECT . ' WHERE u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);

        $users = [];
        foreach ($stmt->fetchAll() as $row) {
            $user = self::fromRow($row);
            $users[$user['userId']] = $user;
        }
        return $users;
    }

    /**
     * Список пользователей по возрастанию id.
     *
     * @param string|null $query подстрока для поиска по name или login
     * @param int|null    $maxId отдавать только пользователей с id больше этого
     */
    public static function findList(?string $query, ?int $maxId, int $count): array
    {
        $where  = [];
        $params = [];

        if ($query !== null && $query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $where[]  = '(u.name LIKE ? OR u.login LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        if ($maxId !== null) {
            $where[]  = 'u.id > ?';
            $params[] = $maxId;
        }

        $sql = self::SELECT
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY u.id ASC LIMIT ' . $count;

        $stmt = DB::get()->prepare($sql);
        $stmt->execute($params);

        return array_map([self::class, 'fromRow'], $stmt->fetchAll());
    }

    /**
     * Собирает объект User из строки запроса. Колонки аватара ожидаются с префиксом f_
     * (см. findById); если f_id пустой — avatar = null.
     */
    public static function fromRow(array $row): array
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
            'userId'  => (int) $row['id'],
            'login'   => $row['login'],
            'name'    => $row['name'],
            'avatar'  => $avatar,
        ];
    }
}
