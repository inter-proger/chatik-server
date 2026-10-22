# Модель данных

[← Обзор архитектуры](Overview.md)

Одна БД MySQL 8 (InnoDB, `utf8mb4`). Схема строится последовательным применением миграций `db/*.sql`; итоговое состояние таблиц описано ниже. Имена таблиц и колонок — `snake_case`, в API они превращаются в `lowerCamelCase` в `fromRow()` сущностей (`core/User.php`, `core/Conversation.php`, …).

## Связи

```
users ─┬─< files                      (files.user_id — кто загрузил)
       │     ▲
       ├─────┘ users.avatar_file_id ──> files            ON DELETE SET NULL
       │
       ├─< conversation_members >─ conversations ─< message_blocks ─< messages
       ├─< conversation_removed_members >─┤  avatar_file_id ──> files     ON DELETE SET NULL
       │                              ├─ last_message_id ──> messages ON DELETE SET NULL
       │                              └─ owner_id ──> users           ON DELETE SET NULL
       ├─< messages (sender_id)
       └─< websocket_tokens
```

Удаление пользователя каскадом удаляет его файлы (записи, не файлы на диске), членство в переписках и метки удаления, отправленные сообщения и WS-токены; у групп, где он владелец, `owner_id` становится `NULL`. Удаление переписки — её участников, метки удаления, блоки и сообщения. Методов удаления в API пока нет.

## Таблицы

### `users` — пользователи

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | INT UNSIGNED, PK | `userId` |
| `login` | VARCHAR(64), UNIQUE | Логин (`\w+`). Уникальность проверяет ключ — без гонки между проверкой и вставкой |
| `password_hash` | VARCHAR(255) | bcrypt (`password_hash(..., PASSWORD_BCRYPT)`) |
| `name` | VARCHAR(128) | Отображаемое имя |
| `avatar_file_id` | INT UNSIGNED NULL → `files.id` | Аватар; `NULL` — нет |
| `total_unread_count` | INT UNSIGNED | Сумма `conversation_members.unread_count` пользователя — общий счётчик непрочитанных |
| `change_version` | INT UNSIGNED | Версия пользователя: +1 на любое изменение его переписок, кроме позиции в списке. Курсор фильтра `changedAfter` — [UnreadMessages.md](UnreadMessages.md#единая-версия) |
| `created_at` | DATETIME(3) | |

### `files` — загруженные файлы

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | INT UNSIGNED, PK | `fileId` |
| `user_id` | → `users.id` CASCADE | Кто загрузил |
| `type` | VARCHAR(16) | `image` или `other` (второй пока не создаётся) |
| `path` | VARCHAR(512) | Путь относительно корня проекта (`static/uploads/2026/10/<hex>.jpg`); URL строится из `BASE_URL` при выдаче |
| `size` | INT UNSIGNED | Размер исходного файла, байт |
| `filename` | VARCHAR(255) | Имя для показа — из параметра или исходное имя файла |
| `extension` | VARCHAR(32) | Расширение из `filename` в нижнем регистре или `file` |
| `data` | JSON | Данные, зависящие от `type`; для `image` — `{"small_path": "…"}` (наружу — `smallUrl`). Ключи — `snake_case` |
| `created_at` | DATETIME(3) | |

Подробнее — [Files.md](Files.md).

### `conversations` — переписки

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | INT UNSIGNED, PK | `conversationId` |
| `type` | VARCHAR(16) | `single` (личная) или `group` (групповая) |
| `name` | VARCHAR(128) NULL | Название группы; для `single` — `NULL`. Лимит — как у `users.name` |
| `description` | VARCHAR(1024) NULL | Описание группы; `NULL` — нет |
| `avatar_file_id` | → `files.id` SET NULL | Аватар группы |
| `owner_id` | → `users.id` SET NULL | Владелец группы — ровно один, поэтому колонка, а не роль участника. `NULL` у группы — участников не осталось |
| `group_info_version` | INT UNSIGNED | Версия GroupInfo (состав и роли): +1 при каждом изменении состава или владельца, под блокировкой переписки. У `single` — 0. Своя шкала группы, не версия пользователя — [Groups.md](Groups.md#модель-данных) |
| `single_key` | VARCHAR(32), UNIQUE | Для `single`: `"<меньший userId>_<больший userId>"` — не больше одной личной переписки на пару. Параллельное создание ловится по ошибке дубликата `1062` |
| `last_message_id` | → `messages.id` SET NULL | Последнее сообщение (`lastMessage` в API) |
| `created_at` | DATETIME(3) | |
| `updated_info_at` | DATETIME(3) | Время изменения информации о переписке, не меняющего позицию в списке: поля переписки, правка последнего сообщения (`updatedInfoAt`) |
| `position_updated_at` | DATETIME(3) | Время подъёма в списке = `created_at` последнего сообщения, без сообщений — время создания (`positionUpdatedAt`, сортировка `/conversations/getList`) |

Зачем две отметки времени — [ConversationList.md](ConversationList.md#изменения-в-conversation).

### `conversation_members` — участники

| Колонка | Тип | Описание |
|---------|-----|----------|
| `conversation_id`, `user_id` | PK (пара) | |
| `last_read_message_id` | INT UNSIGNED | Указатель прочтения: сообщения с `id` не больше прочитаны. Только растёт |
| `unread_count` | INT UNSIGNED | Непрочитанные в переписке: чужие сообщения с `id > last_read_message_id` |
| `change_version` | INT UNSIGNED | `users.change_version` участника на момент последнего изменения переписки для него. Ключ `(user_id, change_version)` — выборка изменившихся переписок |
| `joined_after_message_id` | INT UNSIGNED | `last_message_id` переписки на момент вступления, не меняется: в группе статус «прочитано» считается только по сообщениям после него ([Groups.md](Groups.md#прочтение-для-нового-участника)). У создателей и участников личных переписок — 0 |
| `created_at` | DATETIME(3) NOT NULL | Время вступления. Позиция группы для участника — позже из времени последнего сообщения и вступления ([Groups.md](Groups.md#позиция-в-списке)) |

При удалении из группы и выходе строка удаляется, вместо неё появляется метка в `conversation_removed_members`.

Модель прочтения — [UnreadMessages.md](UnreadMessages.md#модель-данных), прочтение другими участниками — [ReadReceipts.md](ReadReceipts.md).

### `conversation_removed_members` — метки удаления

Пользователь перестал быть участником группы (удалён или вышел) — чтобы синхронизация `changedAfter` вернула ему это изменение, хотя строки участника больше нет. Устройство — [Groups.md](Groups.md#conversation_removed_members--метки-удаления).

| Колонка | Тип | Описание |
|---------|-----|----------|
| `conversation_id`, `user_id` | PK (пара), → CASCADE | |
| `change_version` | INT UNSIGNED | `users.change_version` на момент удаления — та же шкала, что у `conversation_members`. Ключ `(user_id, change_version)` |
| `created_at` | DATETIME(3) | |

Пара (переписка, пользователь) — либо в `conversation_members`, либо здесь, либо нигде: метка удаляется при повторном добавлении в той же транзакции.

### `message_blocks` — блоки сообщений

Сообщения переписки сгруппированы в блоки по `Message::BLOCK_SIZE` (20) мест. Клиент кэширует и подгружает сообщения целыми блоками ([блоки в API](../api/Objects.md#блоки-сообщений)).

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | INT UNSIGNED, PK | `blockId`; внутри переписки растёт вместе с возрастом блока |
| `conversation_id` | → `conversations.id` CASCADE | Ключ `(conversation_id, id)` |
| `messages_count` | INT UNSIGNED | Число **занятых мест**: растёт при отправке, при удалении сообщения не уменьшается |
| `updated_at` | DATETIME(3) NULL | Время последнего изменения уже существующих сообщений блока; `NULL` — не менялись. Новое сообщение его не меняет — [MessageSync.md](MessageSync.md#время-изменения-блока) |
| `created_at` | DATETIME(3) | |

Новое сообщение всегда пишется в последний блок переписки; если в нём уже 20 мест — создаётся новый. Поэтому неполным может быть только последний блок, блоки не удаляются и их границы не сдвигаются — `blockId`, известные клиенту, остаются валидными ([MessageSync.md](MessageSync.md#пустые-блоки)).

### `messages` — сообщения

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | INT UNSIGNED, PK | `messageId`; внутри переписки растёт вместе с `created_at` |
| `conversation_id`, `block_id` | → CASCADE | Ключи `(conversation_id, id)`, `(block_id, id)` |
| `sender_id` | → `users.id` CASCADE | |
| `client_id` | VARCHAR(64) ascii_bin | Идентификатор от клиента; уникален в пределах отправителя (`UNIQUE (sender_id, client_id)`), сравнивается с учётом регистра. Повторная отправка с тем же `clientId` возвращает существующее сообщение. Без `clientId` (админка) сервер генерирует UUID |
| `type` | VARCHAR(16) | Пока только `text` |
| `text` | TEXT | До 4096 символов, обрезанный по краям |
| `is_edited` | TINYINT(1) | |
| `created_at`, `updated_at` | DATETIME(3) | `updatedAt` — версия сообщения при слиянии на клиенте |

### `websocket_tokens` — токены подключения к WebSocket

Одноразовые токены на 120 секунд; хранится только `sha256` токена. Устройство — [WebSocket.md](WebSocket.md#токены-подключения).

## Время

Все колонки времени — `DATETIME(3)` в **UTC** (миграция `010`): `DATETIME` не ограничен 2038 годом, а миллисекунды нужны, чтобы фильтры со строгим сравнением не теряли события в пределах одной секунды. Часовой пояс задаёт приложение — `DB::get()` выполняет `SET time_zone = '+00:00'` на каждом соединении, настраивать MySQL не нужно.

- В API время — Unix timestamp в миллисекундах: выборка через `DB::ms('колонка')` (`CAST(UNIX_TIMESTAMP(x) * 1000 AS UNSIGNED)`), параметры — через `DB::fromMs()`.
- Время, которое должно совпадать до миллисекунды, копируется из строки-источника, а не берётся вторым `CURRENT_TIMESTAMP(3)`: `position_updated_at` = `messages.created_at`, `message_blocks.updated_at` и `updated_info_at` = `messages.updated_at`.

## Денормализованные поля и инварианты

Счётчики и «последние» значения хранятся, а не считаются при чтении. Они обновляются в той же транзакции, что и изменение, и должны выполняться всегда:

| Поле | Инвариант |
|------|-----------|
| `users.total_unread_count` | `= SUM(conversation_members.unread_count)` пользователя |
| `conversation_members.unread_count` | `= COUNT` сообщений переписки с `id > last_read_message_id` и `sender_id ≠ user_id` |
| `conversations.last_message_id` | Сообщение переписки с наибольшим `id` |
| `conversations.position_updated_at` | `created_at` последнего сообщения (без сообщений — время создания) |
| `message_blocks.messages_count` | Сколько сообщений когда-либо записано в блок, не больше 20 |
| `conversation_members.change_version`, `conversation_removed_members.change_version` | Не больше `users.change_version`; ненулевые значения одного пользователя не повторяются по обеим таблицам |
| `conversations.owner_id` | У группы с участниками — один из её участников |

`Conversation::recountUnread(?int $userId)` пересчитывает оба счётчика непрочитанных из указателей прочтения — для ручного исправления и для проверки инварианта в тестах (`TestCase::assertUnreadInvariant()`). Обновления счётчиков защищены `GREATEST(…, 0)`: рассинхронизированный счётчик не должен ломать отправку и прочтение ошибкой `UNSIGNED`.

## Транзакции и блокировки

Изменения переписки сериализуются блокировкой её строки (`SELECT … FROM conversations … FOR UPDATE`). Это гарантирует, что блок не переполнится при параллельных отправках, повтор с тем же `clientId` не создаст дубль, а `created_at` внутри переписки растёт вместе с `id` (на этом построена синхронизация по времени).

Порядок захвата строк одинаковый во всех транзакциях — иначе параллельные запросы сцепятся в дедлок:

1. строка `conversations` (`FOR UPDATE`);
2. строки `conversation_members` этой переписки — в том числе повторная проверка участия (`Conversation::lockMember()`): из группы могли удалить между проверкой в обработчике и транзакцией;
3. строки `users` — одним `UPDATE … WHERE id IN (…) ORDER BY id` или `SELECT … ORDER BY id FOR UPDATE`, по возрастанию id;
4. вставка строк `conversation_members` и `conversation_removed_members` и запись `conversations.owner_id` — только после шага 3: проверка внешнего ключа ставит S-блокировку на строку `users`, и без уже взятой X-блокировки две транзакции с общим пользователем ждали бы друг друга. Создание группы поэтому поднимает версию создателя до вставки самой переписки.

| Операция | Метод | Что меняется |
|----------|-------|--------------|
| Отправка | `Message::send()` | `message_blocks` (новый блок или `messages_count + 1`), `messages`, `conversations.last_message_id` и `position_updated_at`, прочтение: у отправителя указатель на новое сообщение, у остальных `unread_count + 1`; `change_version` у всех участников |
| Правка | `Message::edit()` | `messages`, `message_blocks.updated_at`; если сообщение последнее — `conversations.updated_info_at` и `change_version` участников |
| Прочтение | `Conversation::markRead()` | Указатель и `unread_count` участника, `total_unread_count` и `change_version` пользователя |
| Создание личной переписки | `Conversation::findOrCreateSingle()` | `conversations`, `change_version` обоих, затем `conversation_members` |
| Создание группы | `Group::create()` | `conversations`, `change_version` создателя, его строка участника |
| Добавление в группу | `Group::addMembers()` | `change_version` добавленных, удаление их меток, строки участников (указатель = последнее сообщение), `group_info_version` |
| Удаление из группы, выход | `Group::removeMembers()`, `Group::leave()` | `change_version` и `total_unread_count` удалённых, метки удаления, удаление строк участников, при выходе владельца — `owner_id`; `group_info_version` |
| Смена владельца | `Group::changeOwner()` | `owner_id`, `group_info_version` |

WS-события публикуются **после** коммита: клиент, получивший событие и сразу пошедший в REST, увидит закоммиченные данные.

## Миграции

- Файл `db/NNN_описание.sql`, номер — следующий по порядку. Миграции применяются строго по возрастанию номера; уже применённые не редактируются — исправление делается новой миграцией.
- Учёта применённых миграций в БД нет: на сервере новые файлы применяются вручную ([Deployment.md](../operations/Deployment.md#11-обновление)), тестовый раннер пересоздаёт БД из всех файлов при каждом запуске.
- Миграция, добавляющая денормализованное поле, сразу заполняет его для существующих строк (пример — `011`, `012`, `013`), чтобы инварианты выполнялись с момента применения.
- Комментарии в SQL — что хранит колонка и почему; в первой строке файла — ссылка на архитектурный документ, если он есть.

| Миграция | Что делает |
|----------|------------|
| `000_users` | Пользователи |
| `001_users_avatar` | Аватар двумя URL (заменено в `003`) |
| `002_files` | Файлы |
| `003_users_avatar_file` | Аватар — ссылка на `files` |
| `004_conversations` | Переписки и участники |
| `005_messages` | Блоки и сообщения |
| `006_message_blocks_created_at_not_null` | `message_blocks.created_at NOT NULL` |
| `007_conversations_updated_at` | Время изменения переписки |
| `008_websocket_tokens` | Токены подключения к WebSocket |
| `009_messages_client_id` | `client_id` сообщений, идемпотентная отправка |
| `010_datetime_ms` | Все колонки времени → `DATETIME(3)` в UTC |
| `011_conversations_last_message` | Последнее сообщение, `position_updated_at`, `updated_at` → `updated_info_at` |
| `012_message_blocks_updated_at` | Время изменения блока |
| `013_unread` | Непрочитанные: указатель, счётчики, версии |
| `014_groups` | Группы: описание, владелец, версия GroupInfo, время и точка вступления, метки удаления |
