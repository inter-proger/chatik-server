-- message_blocks.created_at — NOT NULL, как и у messages
UPDATE message_blocks SET created_at = CURRENT_TIMESTAMP WHERE created_at IS NULL;
ALTER TABLE message_blocks MODIFY created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
