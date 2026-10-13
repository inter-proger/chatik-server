-- Время с миллисекундами (в API — Unix timestamp в мс) и без ограничения TIMESTAMP 2038 годом:
-- все колонки времени — DATETIME(3) в UTC. Соединения приложения работают в UTC (см. core/DB.php);
-- time_zone здесь — чтобы существующие TIMESTAMP при ALTER сконвертировались в UTC
SET time_zone = '+00:00';

ALTER TABLE users
    MODIFY created_at DATETIME(3) NULL DEFAULT CURRENT_TIMESTAMP(3);

ALTER TABLE files
    MODIFY created_at DATETIME(3) NULL DEFAULT CURRENT_TIMESTAMP(3);

ALTER TABLE conversations
    MODIFY created_at DATETIME(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
    MODIFY updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3);

ALTER TABLE conversation_members
    MODIFY created_at DATETIME(3) NULL DEFAULT CURRENT_TIMESTAMP(3);

ALTER TABLE message_blocks
    MODIFY created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3);

ALTER TABLE messages
    MODIFY created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    MODIFY updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3);

ALTER TABLE websocket_tokens
    MODIFY expires_at DATETIME(3) NOT NULL,
    MODIFY created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3);
