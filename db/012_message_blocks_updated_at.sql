-- Время последнего изменения или удаления уже существующих сообщений блока (отдаётся в MessageBlock.updatedAt,
-- по нему /messages/getList с receivedAfter находит updatedBlockIds). NULL — сообщения блока не менялись.
-- Добавление нового сообщения в блок его не меняет: новые сообщения клиент получает по createdAt.
-- Существующим блокам — время последней правки их сообщений
ALTER TABLE message_blocks
    ADD COLUMN updated_at DATETIME(3) NULL DEFAULT NULL AFTER messages_count,
    ADD KEY conversation_updated (conversation_id, updated_at);

UPDATE message_blocks b
JOIN (SELECT block_id, MAX(updated_at) AS updated_at FROM messages WHERE is_edited = 1 GROUP BY block_id) e
    ON e.block_id = b.id
SET b.updated_at = e.updated_at;
