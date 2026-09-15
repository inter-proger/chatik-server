# ServerAi

PHP REST API без фреймворка: `index.php` — роутер, обработчики методов лежат в каталогах по префиксу URL (`auth/`, `profile/`, `files/`), общий код — в `core/`.

## Правила именования

- **API (методы, параметры запросов, поля ответов) — `lowerCamelCase`**: `/files/uploadImage`, `/profile/setAvatar`, `userId`, `fileId`, `errorCode`, `smallUrl`.
- **База данных (таблицы, колонки, ключи внутри JSON-колонок) — `snake_case`**: `users`, `avatar_file_id`, `small_path`.
- Преобразование между ними делается в `fromRow()` сущностей (`core/User.php`, `core/File.php`) — наружу из БД snake_case не протекает.

## Прочее

- Документация API ведётся только в `API_Documentation.md`; при добавлении или изменении метода обновлять её в том же изменении.
- Изменения схемы БД — отдельным файлом-миграцией в `db/` с порядковым номером (`004_....sql`).
- `config/db.php` в репозиторий не попадает; шаблон — `config/db.example.php`.
