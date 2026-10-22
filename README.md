# ServerAi

Бэкенд мессенджера на чистом PHP без фреймворка и зависимостей: REST API, WebSocket-сервер для событий в реальном времени и MySQL.

Что умеет:

- регистрация, вход, сессии на cookie;
- профиль и аватар — загрузка изображений с уменьшенной копией;
- поиск пользователей;
- личные переписки, сообщения с правкой и идемпотентной отправкой (`clientId`);
- список чатов с подъёмом по новому сообщению и последним сообщением;
- синхронизация клиентского кэша после оффлайна — списка переписок и сообщений;
- счётчики непрочитанных и прочтение собеседником («галочки»);
- WebSocket-события: новые и изменённые сообщения, новые переписки, прочтение;
- админ-панель `/admin` — действия от имени пользователя.

## Стек

PHP 8.1+ (PHP-FPM и CLI), MySQL 8, nginx. Расширения PHP: `pdo_mysql`, `gd`, `mbstring`, `exif`, `fileinfo`, `pcntl`.

```
клиент ──https──▶ nginx ──▶ PHP-FPM: index.php → обработчики → core/ ──▶ MySQL
       ──wss────▶ nginx ──▶ websocket/server.php ◀── publish (127.0.0.1) ── API
```

## Быстрый старт

```bash
cp config/db.example.php config/db.php
```

```bash
cp config/app.example.php config/app.php
```

Задать в `config/app.php` `WEBSOCKET_INTERNAL_SECRET`, создать БД `serverai`, применить миграции `db/*.sql` по порядку и запустить:

```bash
php -S localhost:8080 index.php
```

```bash
php websocket/server.php
```

Подробно — [руководство разработчика](docs/development/Guide.md#локальный-запуск); установка на сервер — [Deployment.md](docs/operations/Deployment.md).

## Тесты

```bash
php tests/run.php
```

Нужен локальный MySQL: раннер пересоздаёт БД `serverai_test` и сам поднимает HTTP- и WebSocket-сервер. Подробно — [Testing.md](docs/development/Testing.md).

## Структура

```
index.php        роутер REST API
global/ auth/ profile/ files/ users/ conversations/ messages/
                 обработчики методов: файл на метод, каталог = префикс URL
admin/           админ-панель
core/            общий код: инфраструктура (DB, Response, Session, …) и сущности (User, Conversation, Message, File)
websocket/       WebSocket-сервер
config/          настройки (db.php и app.php — локальные, не в git)
db/              миграции схемы БД
static/          загруженные файлы и статичные документы
tests/           раннер, библиотека, юнит- и API-тесты
docs/            документация
```

## Документация

### API — контракт для клиентов

[docs/api/README.md](docs/api/README.md) — оглавление.

| Документ | Содержание |
|----------|------------|
| [General.md](docs/api/General.md) | Формат запросов и ответов, сессия, время, actions |
| [Objects.md](docs/api/Objects.md) | Объекты API: User, File, Conversation, Message, … |
| [Errors.md](docs/api/Errors.md) | Коды ошибок |
| [Methods.md](docs/api/Methods.md) | REST-методы по группам |
| [WebSocket.md](docs/api/WebSocket.md) | Протокол WebSocket и события |
| [Admin.md](docs/api/Admin.md) | Методы админ-панели |

### Архитектура — как устроен сервер

| Документ | Содержание |
|----------|------------|
| [Overview.md](docs/architecture/Overview.md) | Общий обзор: компоненты, жизненный цикл запроса, `core/`, соглашения |
| [DataModel.md](docs/architecture/DataModel.md) | Схема БД, инварианты, транзакции и блокировки, миграции |
| [Sessions.md](docs/architecture/Sessions.md) | Аутентификация, PHP-сессии, сессия админа |
| [Files.md](docs/architecture/Files.md) | Загрузка и хранение изображений |
| [WebSocket.md](docs/architecture/WebSocket.md) | WebSocket-сервер, токены, канал publish |
| [ConversationList.md](docs/architecture/ConversationList.md) | Список чатов: подъём, последнее сообщение, время в мс |
| [MessageSync.md](docs/architecture/MessageSync.md) | Синхронизация кэша сообщений |
| [UnreadMessages.md](docs/architecture/UnreadMessages.md) | Непрочитанные сообщения и версия пользователя |
| [ReadReceipts.md](docs/architecture/ReadReceipts.md) | Прочтение собеседником |

### Эксплуатация

| Документ | Содержание |
|----------|------------|
| [Deployment.md](docs/operations/Deployment.md) | Установка на сервер (nginx, PHP-FPM, systemd, TLS), обновление, локальный сервер через туннель |
| [Configuration.md](docs/operations/Configuration.md) | Справочник настроек `config/` |

### Разработка

| Документ | Содержание |
|----------|------------|
| [Guide.md](docs/development/Guide.md) | Локальный запуск, соглашения, чек-листы изменений |
| [Testing.md](docs/development/Testing.md) | Тесты: запуск, раннер, библиотека, как писать |
| [TestUsers.md](docs/development/TestUsers.md) | Тестовые пользователи локальной БД |
