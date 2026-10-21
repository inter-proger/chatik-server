-- Непрочитанные сообщения (см. Unread_Messages_Architecture.md).
-- conversation_members: указатель прочтения, непрочитанные в переписке и версия пользователя
-- на момент последнего изменения переписки для него; users: общий счётчик и версия пользователя
-- (+1 на любое изменение его переписок, кроме позиции в списке) — курсор фильтра changedAfter.
-- Ключ user_id больше не нужен: его заменяет префикс user_change_version
ALTER TABLE conversation_members
    ADD COLUMN last_read_message_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id,
    ADD COLUMN unread_count         INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_read_message_id,
    ADD COLUMN change_version       INT UNSIGNED NOT NULL DEFAULT 0 AFTER unread_count,
    ADD KEY user_change_version (user_id, change_version),
    DROP KEY user_id;

ALTER TABLE users
    ADD COLUMN total_unread_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER name,
    ADD COLUMN change_version     INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_unread_count;

-- Всё существующее считается прочитанным
UPDATE conversation_members m
JOIN conversations c ON c.id = m.conversation_id
SET m.last_read_message_id = COALESCE(c.last_message_id, 0);
