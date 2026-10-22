# ServerAi

PHP REST API без фреймворка: `index.php` — роутер, обработчики методов лежат в каталогах по префиксу URL (`auth/`, `profile/`, `files/`), общий код — в `core/`. WebSocket-сервер — отдельный процесс `websocket/server.php`. Вход в документацию — `README.md` в корне, устройство кода — `docs/architecture/Overview.md`.

## Правила именования

- **API (методы, параметры запросов, поля ответов) — `lowerCamelCase`**: `/files/uploadImage`, `/profile/setAvatar`, `userId`, `fileId`, `errorCode`, `smallUrl`.
- **База данных (таблицы, колонки, ключи внутри JSON-колонок) — `snake_case`**: `users`, `avatar_file_id`, `small_path`.
- Преобразование между ними делается в `fromRow()` сущностей (`core/User.php`, `core/File.php`) — наружу из БД snake_case не протекает.

## Прочее

- Документация — только markdown, в `docs/` по разделам: `api/` — клиентский контракт, `architecture/` — серверное устройство и решения, `operations/` — развёртывание и конфигурация, `development/` — разработка и тесты. Оглавление всего — `README.md` в корне; новый документ добавлять туда (архитектурный — и в таблицу «Документы раздела» в `docs/architecture/Overview.md`).
- Документация API: `docs/api/README.md` — оглавление (`General.md`, `Objects.md`, `Errors.md`, `Methods.md`, `WebSocket.md`, `Admin.md`); методы — по группам в `docs/api/methods/` (`Global.md`, `Auth.md`, …), `Methods.md` — их оглавление, новый метод добавлять и туда. WS-события — по группам в `docs/api/websocket/` (`Auth.md`, `Messages.md`, …), оглавление — раздел «События» в `WebSocket.md`. При добавлении или изменении метода, объекта, кода ошибки или WS-события обновлять соответствующий документ в том же изменении; ссылки между документами — относительные, `Файл.md#якорь`.
- Изменения схемы БД — отдельным файлом-миграцией в `db/` с порядковым номером (`004_....sql`) и с обновлением `docs/architecture/DataModel.md`. Новая настройка — в `docs/operations/Configuration.md`. Чек-листы изменений — `docs/development/Guide.md`.
- `config/db.php` и `config/app.php` в репозиторий не попадают; шаблоны — `config/db.example.php` и `config/app.example.php`.
- Развёртывание на новом окружении — `docs/operations/Deployment.md`. Новые зависимости (расширения PHP, версии), настройки в шаблонах конфигов, каталоги для записи или процессы — отражать там в том же изменении.
- Тесты: `php tests/run.php [фильтр]` — без зависимостей, нужен локальный MySQL. Раннер пересоздаёт БД `serverai_test` из `db/*.sql` (доступ — `tests/config/db.php`, переопределяется переменными `TEST_DB_*`) и поднимает `php -S` и WebSocket-сервер с тестовым конфигом через `SERVERAI_CONFIG_DIR`. Юнит-тесты сущностей — `tests/unit/`, тесты методов API — `tests/api/`; новый метод или правка метода — вместе с тестами. Подробно — `docs/development/Testing.md`.
