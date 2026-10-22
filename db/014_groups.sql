-- Групповые чаты (см. docs/architecture/Groups.md).
-- conversations: описание, владелец (ровно один — колонка, а не роль участника) и версия GroupInfo —
-- счётчик группы, +1 при каждом изменении состава или владельца; у single всегда 0.
-- Длина name — как у users.name: название группы проверяется тем же лимитом
ALTER TABLE conversations
    MODIFY COLUMN name VARCHAR(128) NULL DEFAULT NULL,
    ADD COLUMN description        VARCHAR(1024) NULL DEFAULT NULL AFTER name,
    ADD COLUMN owner_id           INT UNSIGNED  NULL DEFAULT NULL AFTER description,
    ADD COLUMN group_info_version INT UNSIGNED  NOT NULL DEFAULT 0 AFTER owner_id,
    ADD CONSTRAINT conversations_owner_fk FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL;

-- conversation_members: created_at — время вступления, на нём позиция группы в списке у вступившего
-- (GREATEST с position_updated_at), поэтому NOT NULL. joined_after_message_id — last_message_id переписки
-- на момент вступления, не меняется: статус «прочитано» в группе считается только по сообщениям после него.
-- Существующие участники вступили до всех сообщений — 0
UPDATE conversation_members m
JOIN conversations c ON c.id = m.conversation_id
SET m.created_at = COALESCE(c.created_at, c.position_updated_at)
WHERE m.created_at IS NULL;

ALTER TABLE conversation_members
    MODIFY COLUMN created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    ADD COLUMN joined_after_message_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER change_version;

-- Метки удаления: пользователь перестал быть участником (удалён или вышел). change_version — версия
-- пользователя на момент удаления, та же шкала, что conversation_members.change_version: по ключу
-- (user_id, change_version) метки попадают в /conversations/getList с changedAfter. Пара всегда либо здесь,
-- либо в conversation_members, либо нигде: метка удаляется при повторном добавлении
CREATE TABLE conversation_removed_members (
    conversation_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    change_version  INT UNSIGNED NOT NULL,
    created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (conversation_id, user_id),
    KEY user_change_version (user_id, change_version),
    CONSTRAINT conversation_removed_members_conversation_fk FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT conversation_removed_members_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
