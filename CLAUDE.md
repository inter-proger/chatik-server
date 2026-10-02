# ServerAi

PHP REST API без фреймворка: `index.php` — роутер, обработчики методов лежат в каталогах по префиксу URL (`auth/`, `profile/`, `files/`), общий код — в `core/`. WebSocket-сервер — отдельный процесс `websocket/server.php` (устройство — `WebSocket_Architecture.md`).

## Правила именования

- **API (методы, параметры запросов, поля ответов) — `lowerCamelCase`**: `/files/uploadImage`, `/profile/setAvatar`, `userId`, `fileId`, `errorCode`, `smallUrl`.
- **База данных (таблицы, колонки, ключи внутри JSON-колонок) — `snake_case`**: `users`, `avatar_file_id`, `small_path`.
- Преобразование между ними делается в `fromRow()` сущностей (`core/User.php`, `core/File.php`) — наружу из БД snake_case не протекает.

## Прочее

- Документация API ведётся только в `API_Documentation.md`; при добавлении или изменении метода обновлять её в том же изменении.
- Изменения схемы БД — отдельным файлом-миграцией в `db/` с порядковым номером (`004_....sql`).
- `config/db.php` и `config/app.php` в репозиторий не попадают; шаблоны — `config/db.example.php` и `config/app.example.php`.
- Тесты: `php tests/run.php [фильтр]` — без зависимостей, нужен локальный MySQL. Раннер пересоздаёт БД `serverai_test` из `db/*.sql` (доступ — `tests/config/db.php`, переопределяется переменными `TEST_DB_*`) и поднимает `php -S` и WebSocket-сервер с тестовым конфигом через `SERVERAI_CONFIG_DIR`. Юнит-тесты сущностей — `tests/unit/`, тесты методов API — `tests/api/`; новый метод или правка метода — вместе с тестами.
