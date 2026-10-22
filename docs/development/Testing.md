# Тесты

[← README](../../README.md)

Тесты написаны на собственном мини-фреймворке без зависимостей (`tests/lib/`): юнит-тесты сущностей и API-тесты, которые ходят в настоящий HTTP-сервер и WebSocket-сервер.

## Запуск

```bash
php tests/run.php
```

```bash
php tests/run.php MessagesTest::testSend
```

Аргумент — фильтр: подстрока в имени `Класс::метод`. Вывод — точка на пройденный тест, `F` на упавшую проверку, `E` на исключение; в конце — список падений со строкой теста и итог `OK (N tests)` или `FAILED: x/N passed`. Код выхода ненулевой, если есть падения или в логе серверов найдены фатальные ошибки PHP.

Нужно:

- PHP CLI 8.1+ с расширениями `pdo_mysql`, `gd` (JPEG, PNG, WebP), `mbstring`, `fileinfo`, `curl`, `exif`, `pcntl` — как на сервере ([Deployment.md](../operations/Deployment.md#1-требования)) плюс `curl` для HTTP-клиента тестов;
- локальный MySQL 8, пользователь с правом создавать и удалять БД.

Доступ к MySQL — `tests/config/db.php`: по умолчанию `root` без пароля на `127.0.0.1:3306`, БД `serverai_test`. Переопределяется переменными окружения:

```bash
TEST_DB_USER=serverai TEST_DB_PASS=secret php tests/run.php
```

Полный прогон занимает около полутора минут: часть тестов ждёт таймаутов WebSocket-сервера (они уменьшены до 1–2 с).

## Что делает раннер

`tests/run.php`:

1. Выставляет `SERVERAI_CONFIG_DIR=tests/config` — весь код, включая подпроцессы, берёт тестовые `db.php` и `app.php` ([Configuration.md](../operations/Configuration.md#каталог-локальных-настроек)).
2. Выбирает свободные порты для WS-сервера и передаёт их через `SERVERAI_TEST_WS_PORT` / `SERVERAI_TEST_WS_INTERNAL_PORT` (их читает `tests/config/app.php`).
3. **Пересоздаёт БД**: `DROP DATABASE` + `CREATE DATABASE` + все `db/*.sql` по порядку. Защита — раннер откажется работать, если имя БД не оканчивается на `_test`. Заодно это проверка, что миграции применяются на чистую БД.
4. Поднимает `php -S 127.0.0.1:<порт> index.php` (один воркер; `upload_max_filesize=12M`, `post_max_size=16M`, `memory_limit=256M`) и `websocket/server.php` с короткими таймаутами и малыми лимитами (`TestCase::WS_*`: авторизация 1 с, тишина 2 с, 10 соединений, буфер 64 КБ). Логи обоих — во временный файл.
5. Подключает все `tests/unit/*Test.php` и `tests/api/*Test.php` и запускает каждый метод `test*` каждого неабстрактного наследника `TestCase` на новом экземпляре: `setUp()` → тест → `tearDown()`.
6. В конце печатает из лога серверов сообщения PHP (предупреждения — для информации, `Fatal` / `Uncaught` — валят прогон) и останавливает серверы.

## Изоляция тестов

`TestCase::setUp()` вызывает `resetDatabase()` перед **каждым** тестом:

- удаляет с диска файлы, на которые ссылаются записи `files` (иначе `static/uploads/` копил бы мусор);
- `TRUNCATE` всех таблиц с отключённой проверкой внешних ключей — id снова начинаются с 1.

`tearDown()` закрывает WS-соединения, открытые тестом. Тесты не зависят друг от друга и от порядка запуска. Новую таблицу нужно добавить в список `TRUNCATE` в `resetDatabase()`.

## Библиотека (`tests/lib/`)

| Файл | Что даёт |
|------|----------|
| `TestCase.php` | Базовый класс: подготовка БД, фабрики, проверки |
| `ApiClient.php` | HTTP-клиент на curl с собственным хранилищем cookie — один экземпляр = одно устройство со своей сессией. `get()`, `post()` (с `CURLFile` — multipart), `lastStatus`, `lastHeaders`. Ответ должен быть JSON, иначе исключение |
| `WsClient.php` | Минимальный WebSocket-клиент: handshake, `send(group, event, body)`, `receive(timeout)` (сам отвечает pong на ping), `ack()`, `ping()`, `sendFrame()` / `sendText()` для проверки ошибок протокола, `closeCode` после закрытия сервером |
| `Fixtures.php` | Временные файлы для загрузки: `image(type, w, h)` (JPEG, PNG, WebP, GIF через GD), `pngHeaderOnly()` (заголовок с огромными размерами без данных), `bytes()`, `write()`. Удаляются в конце процесса |
| `AssertionFailed.php` | Исключение упавшей проверки — отличает `F` от `E` |

Фабрики `TestCase`:

| Метод | Что делает |
|-------|------------|
| `client()` | Новый `ApiClient` без сессии |
| `signup($login, $name, $password)` | Регистрация через API → `[ApiClient, User]`, клиент уже авторизован |
| `insertUser($login)` | Пользователь напрямую в БД (bcrypt с `cost 4` — быстрее), возвращает id. Для тестов, которым не нужна сессия |
| `insertFile($userId, $type, $data)` | Запись `files` без файла на диске |
| `ws()` | Неавторизованное WS-соединение |
| `wsToken($client)` | Токен через `/global/getWebsocketToken` |
| `wsConnect($client, $userId)` | Авторизованное WS-соединение (проверяет `auth` / `connected`) |

Проверки: `assertSame` (строгое `===`, вывод в JSON), `assertTrue`, `assertFalse`, `assertNull`, `assertCount`, `assertStringStartsWith`, а также специфичные для API:

| Проверка | Что проверяет |
|----------|---------------|
| `assertSuccess($res)` | `status: 1` |
| `assertError($code, $res)` | `status: 0` и нужный `errorCode` |
| `assertUsersAction($ids, $res)` | В ответе ровно один action `users` с этими id |
| `assertEvent($group, $event, $body, $message, $userIds)` | WS-сообщение — это событие с таким `body`; `uid` — UUID v4 |
| `assertClosed($code, $ws)` | Сервер закрыл соединение с кодом `$code` |
| `assertUnreadInvariant()` | Счётчики непрочитанных сходятся с указателями прочтения — `Conversation::recountUnread()` ничего не меняет ([DataModel.md](../architecture/DataModel.md#денормализованные-поля-и-инварианты)) |

## Где какие тесты

| Каталог | Что | Как |
|---------|-----|-----|
| `tests/unit/` | Сущности и вспомогательные классы `core/`, `websocket/` (`MessageTest`, `ConversationTest`, `RequestTest`, `WsFrameTest`, …) | Вызывают статические методы напрямую, данные готовят через `insertUser()` / прямые SQL. Работают с той же тестовой БД |
| `tests/api/` | Методы API по группам (`AuthTest`, `MessagesTest`, `AdminTest`, …), роутер (`RouterTest`), WebSocket-сервер (`WsServerTest`) и события (`WsEventsTest`) | Только через HTTP и WS, как настоящий клиент |

Тест метода API обычно проверяет:

- успешный ответ целиком (`assertSame` с полным ожидаемым массивом или сравнение с сущностью из `core/`) и actions;
- каждый код ошибки из документации метода, включая порядок проверок (какая ошибка побеждает при нескольких невалидных параметрах);
- `2003` без авторизации;
- рассылку WS-событий (`wsConnect()` + `receive()` + `assertEvent()`), в том числе что событий нет, когда их быть не должно;
- для методов, меняющих прочтение и счётчики, — `assertUnreadInvariant()`.

## Пример

```php
<?php

class ExampleTest extends TestCase
{
    public function testSendDeliversEvent(): void
    {
        [$alice, $a] = $this->signup('alice');
        [$bob, $b]   = $this->signup('bob');
        $ws = $this->wsConnect($bob, $b['userId']);

        $conversationId = $alice->post('/conversations/createSingle', ['userId' => $b['userId']])
            ['response']['conversation']['conversationId'];
        $ws->receive(); // conversations / singleCreated

        $res = $alice->post('/messages/send', [
            'conversationId' => $conversationId, 'text' => 'Hi', 'clientId' => 'c1',
        ]);
        $this->assertSuccess($res);

        $event = $ws->receive();
        $this->assertSame('received', $event['event']);
        $this->assertSame('Hi', $event['body']['message']['text']);
        $this->assertUnreadInvariant();
    }
}
```

Новый файл `tests/api/<Группа>Test.php` подхватывается раннером автоматически; общие зависимости (`core/DB.php`, `core/File.php`, `core/Conversation.php`) уже подключены в `TestCase.php`, остальные классы `core/` тест подключает сам через `require_once`.

## Ручная проверка

Для ручных запросов к локальному серверу (`php -S localhost:8080 index.php` и `php websocket/server.php`) в локальной БД заведены тестовые пользователи — [TestUsers.md](TestUsers.md). Раннер работает с отдельной БД, их там нет.
