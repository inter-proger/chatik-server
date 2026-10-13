# ServerAi

PHP REST API без фреймворка: `index.php` — роутер, обработчики методов лежат в каталогах по префиксу URL (`auth/`, `profile/`, `files/`), общий код — в `core/`. WebSocket-сервер — отдельный процесс `websocket/server.php` (устройство — `WebSocket_Architecture.md`).

## Правила именования

- **API (методы, параметры запросов, поля ответов) — `lowerCamelCase`**: `/files/uploadImage`, `/profile/setAvatar`, `userId`, `fileId`, `errorCode`, `smallUrl`.
- **База данных (таблицы, колонки, ключи внутри JSON-колонок) — `snake_case`**: `users`, `avatar_file_id`, `small_path`.
- Преобразование между ними делается в `fromRow()` сущностей (`core/User.php`, `core/File.php`) — наружу из БД snake_case не протекает.

## Прочее

- Документация API ведётся только в markdown: корень `API_Documentation.md` — оглавление со ссылками на `docs/api/` (`General.md`, `Objects.md`, `Errors.md`, `Methods.md`, `WebSocket.md`, `Admin.md`); методы — по группам в `docs/api/methods/` (`Global.md`, `Auth.md`, …), `Methods.md` — их оглавление, новый метод добавлять и туда. WS-события — по группам в `docs/api/websocket/` (`Auth.md`, `Messages.md`, …), оглавление — раздел «События» в `WebSocket.md`. При добавлении или изменении метода, объекта, кода ошибки или WS-события обновлять соответствующий документ в том же изменении; ссылки между документами — `Файл.md#якорь`.
- Изменения схемы БД — отдельным файлом-миграцией в `db/` с порядковым номером (`004_....sql`).
- `config/db.php` и `config/app.php` в репозиторий не попадают; шаблоны — `config/db.example.php` и `config/app.example.php`.
- Тесты: `php tests/run.php [фильтр]` — без зависимостей, нужен локальный MySQL. Раннер пересоздаёт БД `serverai_test` из `db/*.sql` (доступ — `tests/config/db.php`, переопределяется переменными `TEST_DB_*`) и поднимает `php -S` и WebSocket-сервер с тестовым конфигом через `SERVERAI_CONFIG_DIR`. Юнит-тесты сущностей — `tests/unit/`, тесты методов API — `tests/api/`; новый метод или правка метода — вместе с тестами.
