-- Блоки сообщений: сообщения переписки группируются в блоки по MESSAGE_BLOCK_SIZE штук (см. core/Message.php).
-- Новый блок создаётся, когда последний блок переписки заполнен
CREATE TABLE message_blocks (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    messages_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY conversation_id (conversation_id, id),
    CONSTRAINT message_blocks_conversation_fk FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Сообщения
CREATE TABLE messages (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    block_id        INT UNSIGNED NOT NULL,
    sender_id       INT UNSIGNED NOT NULL,
    type            VARCHAR(16)  NOT NULL,              -- text
    text            TEXT         NOT NULL,
    is_edited       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY block_id (block_id, id),
    KEY conversation_id (conversation_id, id),
    KEY sender_id (sender_id),
    CONSTRAINT messages_conversation_fk FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT messages_block_fk FOREIGN KEY (block_id) REFERENCES message_blocks (id) ON DELETE CASCADE,
    CONSTRAINT messages_sender_fk FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
