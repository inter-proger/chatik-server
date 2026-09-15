-- Загруженные файлы
CREATE TABLE files (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(16)  NOT NULL,              -- image | other
    path       VARCHAR(512) NOT NULL,              -- путь относительно корня проекта
    size       INT UNSIGNED NOT NULL,              -- байты
    filename   VARCHAR(255) NOT NULL,
    extension  VARCHAR(32)  NOT NULL,
    data       JSON         NOT NULL,              -- доп. информация, зависит от type
    created_at TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    CONSTRAINT files_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
