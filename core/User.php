<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/File.php';

class User
{
    /**
     * Возвращает объект User по id или null, если пользователь не найден.
     *
     * Формат: user_id, login, name, avatar (сущность File или null)
     */
    public static function findById(int $id): ?array
    {
        $stmt = DB::get()->prepare(
            'SELECT u.id, u.login, u.name,
                    f.id AS f_id, f.type AS f_type, f.path AS f_path, f.size AS f_size,
                    f.filename AS f_filename, f.extension AS f_extension, f.data AS f_data
             FROM users u
             LEFT JOIN files f ON f.id = u.avatar_file_id
             WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? self::fromRow($row) : null;
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
            'user_id' => (int) $row['id'],
            'login'   => $row['login'],
            'name'    => $row['name'],
            'avatar'  => $avatar,
        ];
    }
}
