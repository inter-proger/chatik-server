-- Переписки: личные (single) и групповые (group)
CREATE TABLE conversations (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type           VARCHAR(16)  NOT NULL,              -- single | group
    name           VARCHAR(255) NULL DEFAULT NULL,     -- только для group
    avatar_file_id INT UNSIGNED NULL DEFAULT NULL,     -- только для group
    single_key     VARCHAR(32)  NULL DEFAULT NULL,     -- для single: "<меньший user_id>_<больший user_id>", гарантирует одну переписку на пару
    created_at     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY single_key (single_key),
    CONSTRAINT conversations_avatar_file_fk FOREIGN KEY (avatar_file_id) REFERENCES files (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Участники переписок
CREATE TABLE conversation_members (
    conversation_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, user_id),
    KEY user_id (user_id),
    CONSTRAINT conversation_members_conversation_fk FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT conversation_members_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
