-- Последнее сообщение переписки и время её подъёма в списке (сортировка /conversations/getList).
-- updated_at переименован в updated_info_at: время изменения информации о переписке, не меняющего её позицию
-- (поля переписки и правка последнего сообщения); новые сообщения меняют только position_updated_at
ALTER TABLE conversations
    RENAME COLUMN updated_at TO updated_info_at,
    ADD COLUMN last_message_id     INT UNSIGNED NULL DEFAULT NULL AFTER single_key,
    ADD COLUMN position_updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER updated_info_at,
    ADD CONSTRAINT conversations_last_message_fk FOREIGN KEY (last_message_id) REFERENCES messages (id) ON DELETE SET NULL;

-- Существующим перепискам: последнее сообщение и его время, без сообщений — время создания
UPDATE conversations c
LEFT JOIN (SELECT conversation_id, MAX(id) AS id FROM messages GROUP BY conversation_id) lm ON lm.conversation_id = c.id
LEFT JOIN messages m ON m.id = lm.id
SET c.last_message_id = m.id,
    c.position_updated_at = COALESCE(m.created_at, c.created_at, CURRENT_TIMESTAMP(3));
