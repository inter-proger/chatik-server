-- Пользователи (исходная схема до 001; аватар добавляется миграциями 001 и 003)
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    login         VARCHAR(64)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(128) NOT NULL,
    created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
