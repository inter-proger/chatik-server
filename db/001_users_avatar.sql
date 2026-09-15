-- Аватары пользователя (опциональные поля)
ALTER TABLE users
    ADD COLUMN avatar_url       VARCHAR(512) NULL DEFAULT NULL AFTER name,
    ADD COLUMN small_avatar_url VARCHAR(512) NULL DEFAULT NULL AFTER avatar_url;
