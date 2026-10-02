-- Время последнего изменения полей переписки (участники, название, аватар); новые сообщения его не меняют.
-- Существующим перепискам — время создания
ALTER TABLE conversations ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at;
UPDATE conversations SET updated_at = created_at WHERE created_at IS NOT NULL;
