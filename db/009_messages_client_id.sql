-- Идентификатор сообщения, сгенерированный клиентом (см. /messages/send): по нему клиент сопоставляет
-- локально отправленное сообщение с серверным, а повторная отправка с тем же client_id не создаёт дубль.
-- Уникален в пределах отправителя; сравнивается с учётом регистра. Существующим сообщениям — UUID.
-- Ключ sender_id больше не нужен: его заменяет префикс sender_client_id
ALTER TABLE messages ADD COLUMN client_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER sender_id;
UPDATE messages SET client_id = UUID() WHERE client_id IS NULL;
ALTER TABLE messages
    MODIFY client_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD UNIQUE KEY sender_client_id (sender_id, client_id),
    DROP KEY sender_id;
