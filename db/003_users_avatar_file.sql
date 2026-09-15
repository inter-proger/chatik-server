-- Аватар пользователя теперь ссылается на сущность файла вместо двух url-колонок
ALTER TABLE users
    DROP COLUMN avatar_url,
    DROP COLUMN small_avatar_url,
    ADD COLUMN avatar_file_id INT UNSIGNED NULL DEFAULT NULL AFTER name,
    ADD CONSTRAINT users_avatar_file_fk FOREIGN KEY (avatar_file_id) REFERENCES files (id) ON DELETE SET NULL;
