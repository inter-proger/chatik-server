# Конфигурация

[← README](../../README.md)

Все настройки — PHP-константы (`define`) в каталоге `config/`. Файлы делятся на две группы:

| Файл | В git | Что внутри |
|------|-------|------------|
| `config/db.php` | Нет, шаблон `config/db.example.php` | Доступ к MySQL |
| `config/app.php` | Нет, шаблон `config/app.example.php` | Адреса и секреты окружения |
| `config/files.php` | Да | Лимиты загрузки файлов |
| `config/session.php` | Да | Сессии |
| `config/websocket.php` | Да | Таймауты и лимиты WebSocket |
| `config/local.php` | Да | Где искать `db.php` и `app.php` |

Локальные файлы создаются копированием шаблонов (`cp config/db.example.php config/db.php`, то же для `app`), значения для сервера — в [Deployment.md](Deployment.md#5-конфигурация). Новая настройка окружения добавляется в шаблон, и об этом пишется в [Deployment.md](Deployment.md#11-обновление): при обновлении её нужно перенести в локальные файлы вручную.

## Каталог локальных настроек

`config/local.php` задаёт `LOCAL_CONFIG_DIR` — каталог, из которого подключаются `db.php` и `app.php`. По умолчанию это `config/`; если задана переменная окружения `SERVERAI_CONFIG_DIR` — она. Так тестовый раннер подставляет `tests/config/` с тестовой БД и своими портами, не трогая рабочие настройки.

## `db.php`

| Константа | Пример | Описание |
|-----------|--------|----------|
| `DB_HOST` | `127.0.0.1` | Хост MySQL |
| `DB_PORT` | `3306` | Порт |
| `DB_NAME` | `serverai` | Имя БД |
| `DB_USER`, `DB_PASS` | | Учётная запись |
| `DB_CHARSET` | `utf8mb4` | Кодировка соединения; менять не нужно |

## `app.php`

| Константа | Пример (локально) | Описание |
|-----------|-------------------|----------|
| `BASE_URL` | `http://localhost:8080` | Внешний адрес API без завершающего `/`. От него строятся `url` / `smallUrl` файлов; при `https://` cookie сессии получает флаг `Secure` |
| `WEBSOCKET_URL` | `ws://localhost:8081` | Внешний адрес WS-сервера — отдаётся клиенту в `WebsocketConnectInfo.url`. За nginx — `wss://домен/ws` |
| `WEBSOCKET_LISTEN` | `tcp://0.0.0.0:8081` | Где WS-сервер принимает клиентов. За nginx — `tcp://127.0.0.1:8081` |
| `WEBSOCKET_INTERNAL_PORT` | `8082` | Порт внутреннего эндпоинта WS-сервера (`/publish`, `/closeSession`); слушает только `127.0.0.1` |
| `WEBSOCKET_INTERNAL_SECRET` | случайная строка | Секрет вызовов API → WS-сервер (заголовок `X-Internal-Secret`). Уникальный для окружения: `php -r 'echo bin2hex(random_bytes(32)), "\n";'` |

У `WEBSOCKET_URL`, `WEBSOCKET_LISTEN` и `WEBSOCKET_INTERNAL_PORT` есть значения по умолчанию в `config/websocket.php` (для `app.php`, созданных до появления WebSocket). У `WEBSOCKET_INTERNAL_SECRET` умолчания нет: без него API пропускает рассылку событий (с записью в лог), а `websocket/server.php` не запускается.

## `files.php`

| Константа | Значение | Описание |
|-----------|----------|----------|
| `UPLOAD_DIR` | `static/uploads` | Каталог загрузок относительно корня проекта; должен быть доступен на запись пользователю PHP и отдаваться nginx как статика |
| `UPLOAD_MAX_SIZE` | 10 МБ | Максимальный размер файла. `upload_max_filesize` и `post_max_size` в `php.ini` и `client_max_body_size` в nginx — не меньше |
| `IMAGE_SMALL_SIDE` | 200 | Меньшая сторона уменьшенной копии, px |
| `IMAGE_MAX_PIXELS` | 25 000 000 | Максимум пикселей (ширина × высота) — защита памяти при декодировании |
| `IMAGE_MEMORY_LIMIT` | `256M` | `memory_limit` на время создания уменьшенной копии; должен вмещать `IMAGE_MAX_PIXELS` |

Подробнее — [Files.md](../architecture/Files.md).

## `session.php`

| Константа | Значение | Описание |
|-----------|----------|----------|
| `SESSION_LIFETIME` | 30 дней | Время жизни сессии с последнего авторизованного запроса (cookie и `gc_maxlifetime`) |
| `SESSION_DIR` | `storage/sessions` | Каталог файлов сессий относительно корня проекта; создаётся сам, родитель `storage/` должен быть доступен на запись |

Подробнее — [Sessions.md](../architecture/Sessions.md).

## `websocket.php`

| Константа | Значение | Описание |
|-----------|----------|----------|
| `WEBSOCKET_TOKEN_TTL` | 120 с | Время жизни токена подключения |
| `WEBSOCKET_AUTH_TIMEOUT` | 10 с | Сколько ждать `auth` / `connect` после открытия соединения (иначе закрытие `4002`) |
| `WEBSOCKET_IDLE_TIMEOUT` | 60 с | Соединение без фреймов дольше этого — разрывается. Клиент шлёт ping каждые 25 с |
| `WEBSOCKET_MAX_MESSAGE_SIZE` | 64 КБ | Максимальный размер сообщения клиента (иначе `1009`) |
| `WEBSOCKET_ACK_TIMEOUT` | 30 с | Через сколько неподтверждённое событие пишется в лог |
| `WEBSOCKET_MAX_UNACKED` | 1000 | Сколько неподтверждённых `uid` хранится на соединение |
| `WEBSOCKET_INTERNAL_CONNECT_TIMEOUT` | 0,05 с | Таймаут подключения API → WS-сервер |
| `WEBSOCKET_INTERNAL_TIMEOUT` | 0,2 с | Таймаут всего вызова API → WS-сервер |
| `WEBSOCKET_MAX_CONNECTIONS` | 1000 | Максимум клиентских соединений (ограничение `stream_select()` — 1024 дескриптора) |
| `WEBSOCKET_MAX_OUTPUT_BUFFER` | 1 МБ | Максимум неотправленных данных соединения; больше — разрыв |

`WEBSOCKET_AUTH_TIMEOUT`, `WEBSOCKET_IDLE_TIMEOUT`, `WEBSOCKET_MAX_CONNECTIONS` и `WEBSOCKET_MAX_OUTPUT_BUFFER` переопределяются опциями `websocket/server.php` (`--auth-timeout`, `--idle-timeout`, `--max-connections`, `--max-output-buffer`) — их использует тестовый раннер. Подробнее — [WebSocket.md](../architecture/WebSocket.md#конфигурация).

## Константы в коде

Лимиты, которые являются частью API-контракта, лежат не в `config/`, а рядом с кодом — их изменение меняет документацию API:

| Где | Что |
|-----|-----|
| `core/Message.php` | `BLOCK_SIZE` (20 сообщений в блоке), `TEXT_MAX_LENGTH` (4096), `CLIENT_ID_MAX_LENGTH` (64), `BLOCK_BATCH_MAX_COUNT` (10) |
| `auth/signup.php` | `LOGIN_MAX_LENGTH` (64), `NAME_MAX_LENGTH` (128) — по размерам колонок |
| `users/getList.php`, `conversations/getList.php`, `admin/getConversations.php` | `*_DEFAULT_COUNT` (30), `*_MAX_COUNT` (100) — размер страницы |
| `core/Admin.php` | Логин и пароль админки — временно, до выноса в `app.php` |

## Тестовый конфиг

`tests/config/db.php` — БД `serverai_test` (имя обязано оканчиваться на `_test`), параметры переопределяются переменными `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER`, `TEST_DB_PASS`. `tests/config/app.php` — `BASE_URL` `http://files.test`, порты WS-сервера из переменных, которые выставляет раннер. Подробнее — [Testing.md](../development/Testing.md).
