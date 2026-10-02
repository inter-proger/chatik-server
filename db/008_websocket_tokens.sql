-- Одноразовые токены подключения к WebSocket (см. core/WebSocket.php).
-- Хранится только sha256 токена; запись удаляется при первой попытке авторизации соединения
CREATE TABLE websocket_tokens (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash  CHAR(64)     NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    session_id  VARCHAR(128) NOT NULL,          -- id PHP-сессии, для которой выдан токен
    expires_at  TIMESTAMP    NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY token_hash (token_hash),
    KEY session_id (session_id),
    KEY expires_at (expires_at),
    CONSTRAINT websocket_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
