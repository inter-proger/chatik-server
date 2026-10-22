# Список переписок: поднятие чатов и последнее сообщение

[← Обзор архитектуры](Overview.md)

Описывает, как переписка поднимается наверх списка при новом сообщении, как в списке показывается последнее сообщение и как клиент синхронизирует список после оффлайна. Реализовано; клиентский контракт — в `docs/api/` ([Conversation и LastMessage](../api/Objects.md#объект-conversation), [слияние переписки](../api/WebSocket.md#слияние-переписки), [`/conversations/getList` и `getBatch`](../api/methods/Conversations.md), [события `messages`](../api/websocket/Messages.md)), здесь — серверная сторона и обоснования. При расхождении верен `docs/api/`.

> **Фильтр `updatedInfoAfter` / `updatedInfoAfterId` заменён на `changedAfter`** — курсор по версии пользователя, который ловит и изменения информации, и прочтение (см. [UnreadMessages.md](UnreadMessages.md)). Описание `updatedInfoAfter` ниже (сценарий 2 и связанное) — история решения; актуальный контракт — [синхронизация списка](../api/methods/Conversations.md#синхронизация-списка).

## Коротко

- Новая сущность **LastMessage** — краткое представление последнего сообщения для списка чатов.
- В **Conversation** добавляются `lastMessage` и `positionUpdatedAt`, поле `updatedAt` переименовывается в `updatedInfoAt`.
- У Conversation теперь две независимые версии — позиция и информация; клиент сливает их по [правилам слияния](#слияние-переписки-на-клиенте), а не «целый объект с большим `updatedAt`».
- `/conversations/getList` сортирует по `positionUpdatedAt` (по убыванию) и получает фильтры `positionUpdatedAfter` и `updatedInfoAfter` (у второго — своя сортировка и курсорная пагинация).
- Новый метод **`/conversations/getBatch`** — переписки по списку id, для тех, что ещё не прогружены на клиенте.
- WS-событие `messages` / `received` получает `lastMessage`; `messages` / `edited` и ответ `/messages/edit` — `lastMessage`, если отредактировано последнее сообщение.
- **Все хранимые timestamp в API — в миллисекундах** (ломающее изменение), в БД — `DATETIME(3)` в UTC.

## Время в миллисекундах

Все поля API, которые отдают хранимое на сервере время (`createdAt`, `updatedAt`, `updatedInfoAt`, `positionUpdatedAt` и последующие), — Unix timestamp **в миллисекундах** (Int, 64 бита на клиенте). Секунд не хватает: за одну секунду в переписке может прийти несколько сообщений, и фильтры `positionUpdatedAfter` / `updatedInfoAfter` со строгим сравнением начали бы терять изменения.

Не меняются длительности: `expiresIn` в `WebsocketConnectInfo`, таймауты протокола WebSocket — остаются в секундах.

### Хранение времени

Миграция `db/010_datetime_ms.sql` переводит **все** колонки времени (`created_at`, `updated_at`, `expires_at` во всех таблицах) из `TIMESTAMP` в `DATETIME(3)` с `DEFAULT CURRENT_TIMESTAMP(3)`. `TIMESTAMP` ограничен 2038 годом; раз колонки всё равно перестраиваются, а проект не в проде, переходим сразу, чтобы не делать вторую такую миграцию.

`DATETIME` не хранит часовой пояс, поэтому время везде в UTC:

- `core/DB.php` при подключении выполняет `SET time_zone = '+00:00'` (`PDO::MYSQL_ATTR_INIT_COMMAND`) — от этого зависят `CURRENT_TIMESTAMP(3)`, `NOW()` и `UNIX_TIMESTAMP()`, в том числе в процессе WebSocket-сервера;
- миграция начинается с `SET time_zone = '+00:00';`, чтобы существующие `TIMESTAMP` при `ALTER` конвертировались в UTC. Существующие значения получают `.000`.

Записи времени в коде — `CURRENT_TIMESTAMP(3)` (или значение из другой строки, см. ниже). Выборка — `CAST(UNIX_TIMESTAMP(x) * 1000 AS UNSIGNED)` (у `DATETIME(3)` результат `UNIX_TIMESTAMP` точный, округление не нужно); выражение выносится в хелпер `core/DB.php`, чтобы не дублировать по сущностям.

Изменение ломающее: клиенты, читающие `createdAt` / `updatedAt` как секунды, должны обновиться одновременно с сервером. Отдельной версии API не заводим — проект ещё не в проде.

## Объект LastMessage

Краткое представление последнего сообщения переписки для списка чатов.

| Поле        | Тип            | Описание                                                                 |
|-------------|----------------|--------------------------------------------------------------------------|
| `messageId` | Int            | Идентификатор сообщения ([Message](../api/Objects.md#объект-message).`messageId`) |
| `type`      | String         | Тип сообщения — как `Message.type` (пока только `text`)                  |
| `text`      | String \| null | Текст сообщения; `null` для типов без текста (появятся позже)            |
| `updatedAt` | Int            | Время последнего изменения сообщения — как `Message.updatedAt`, миллисекунды. Версия LastMessage при [слиянии](#слияние-переписки-на-клиенте) |

```json
{
  "messageId": 15,
  "type": "text",
  "text": "Привет!",
  "updatedAt": 1790846400123
}
```

Отправитель и время отправки в LastMessage не входят: время перемещения чата — `positionUpdatedAt` переписки (он же `createdAt` последнего сообщения). `updatedAt` нужен, чтобы правки одного и того же последнего сообщения, пришедшие не по порядку, не откатывали текст назад. Если клиенту понадобится показывать имя отправителя в групповых чатах — добавим `senderId` отдельным изменением.

## Изменения в Conversation

| Поле                | Тип                  | Описание |
|---------------------|----------------------|----------|
| `lastMessage`       | LastMessage \| null  | Последнее сообщение переписки; `null`, пока сообщений нет, а также если последнее сообщение удалено (например, вместе с удалённым пользователем). Обновляется при отправке нового сообщения и при редактировании последнего |
| `positionUpdatedAt` | Int                  | Время, когда переписка последний раз поднималась в списке, — `createdAt` последнего сообщения, до первого сообщения — время создания переписки. Миллисекунды |
| `updatedInfoAt`     | Int                  | Бывший `updatedAt`, переименован, смысл расширяется. Время последнего изменения информации о переписке, **не меняющего её позицию**: поля переписки (участники, название, аватар) и правка последнего сообщения. Новое сообщение `updatedInfoAt` не меняет — его отражает `positionUpdatedAt`. Миллисекунды |

Переименование — чтобы не путать с `Message.updatedAt` и не создавать впечатления, что поле отражает любое изменение переписки (новые сообщения его не трогают). Затрагивает все места, где отдаётся Conversation: `/conversations/createSingle`, `/conversations/getList`, WS-событие `conversations` / `singleCreated`.

Новая переписка сразу попадает в список **у всех участников** — с `lastMessage: null` и `positionUpdatedAt`, равным времени создания, то есть наверх. Это принятое решение: пустой чат, созданный собеседником, виден до первого сообщения.

```json
{
  "conversationId": 1,
  "type": "single",
  "memberIds": [1, 2],
  "name": null,
  "avatar": null,
  "opponentUserId": 2,
  "lastMessage": {
    "messageId": 15,
    "type": "text",
    "text": "Привет!",
    "updatedAt": 1790846400123
  },
  "positionUpdatedAt": 1790846400123,
  "updatedInfoAt": 1790846000456
}
```

### Хранение

Миграция `db/011_conversations_last_message.sql` (после перевода времени в `010_datetime_ms.sql`). Колонка `updated_at` переименовывается в `updated_info_at` вслед за полем API (`RENAME COLUMN` — MySQL 8.0+):

```sql
ALTER TABLE conversations
    RENAME COLUMN updated_at TO updated_info_at,
    ADD COLUMN last_message_id     INT UNSIGNED NULL DEFAULT NULL AFTER single_key,
    ADD COLUMN position_updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER updated_info_at,
    ADD CONSTRAINT conversations_last_message_fk FOREIGN KEY (last_message_id) REFERENCES messages (id) ON DELETE SET NULL;

-- Существующим перепискам: последнее сообщение и его время, без сообщений — время создания
UPDATE conversations c
LEFT JOIN (SELECT conversation_id, MAX(id) AS id FROM messages GROUP BY conversation_id) lm ON lm.conversation_id = c.id
LEFT JOIN messages m ON m.id = lm.id
SET c.last_message_id = m.id,
    c.position_updated_at = COALESCE(m.created_at, c.created_at, CURRENT_TIMESTAMP(3));
```

- В переписке хранится только ссылка `last_message_id`; `type`, `text` и `updated_at` берутся JOIN-ом с `messages` в общем `SELECT` из `core/Conversation.php`. Копию текста не храним — правке сообщения не нужно синхронизировать два места, а `ON DELETE SET NULL` готовит почву под удаление сообщений.
- Последнее сообщение — сообщение с максимальным `id` в переписке (id растут монотонно, а вставка идёт под блокировкой строки переписки, см. ниже).
- `position_updated_at` при создании переписки — время создания (`DEFAULT`).
- Индекс под сортировку списка: выборка идёт по `conversation_members.user_id` с JOIN на `conversations` и `ORDER BY c.position_updated_at DESC, c.id DESC` — это filesort по перепискам пользователя. Для сотен и единиц тысяч переписок на пользователя приемлемо. Если станет узким местом — денормализуем `position_updated_at` и `updated_info_at` в `conversation_members` с индексами `(user_id, position_updated_at, conversation_id)` и `(user_id, updated_info_at, conversation_id)`.

## Отправка сообщения (`/messages/send`)

`Message::send()` уже открывает транзакцию и берёт `SELECT ... FOR UPDATE` строки переписки. В той же транзакции после вставки сообщения:

```sql
UPDATE conversations c JOIN messages m ON m.id = ?
SET c.last_message_id = m.id, c.position_updated_at = m.created_at
WHERE c.id = ?
```

Время берётся из строки вставленного сообщения, а не вычисляется в PHP: так `positionUpdatedAt` совпадает с `message.createdAt` до миллисекунды, и не смешиваются часы PHP-хоста и MySQL. `updated_info_at` переписки не меняется.

Блокировка строки переписки гарантирует, что сообщения одной переписки вставляются последовательно, поэтому `last_message_id` и `position_updated_at` не откатываются назад конкурирующей отправкой.

Повтор с тем же `clientId` (сообщение уже существует) переписку не трогает и событие не рассылает — как сейчас.

Ответ `/messages/send` не меняется: клиент-отправитель поднимает переписку сразу по ответу, не дожидаясь события, — `positionUpdatedAt = message.createdAt`, `lastMessage` собирается из `message`.

### WS-событие `messages` / `received`

В `body` добавляется поле:

| Поле `body`         | Тип         | Описание |
|---------------------|-------------|----------|
| `message`           | Message     | Как сейчас |
| `lastMessage`       | LastMessage | Новое последнее сообщение переписки |

Переписка и её новая позиция в `body` не дублируются: это `message.conversationId` и `message.createdAt` (`positionUpdatedAt` переписки по построению равен `createdAt` её последнего сообщения). То же правило — не повторять то, что уже есть в `message`, — действует для ответа `/messages/edit` и события `edited`.

```json
{
  "uid": "7d2b9e4f-1a3c-4e5d-8f6a-2b4c6d8e0a1b",
  "group": "messages",
  "event": "received",
  "body": {
    "message": { "messageId": 15, "conversationId": 1, "createdAt": 1790846400123, "...": "..." },
    "lastMessage": { "messageId": 15, "type": "text", "text": "Привет!", "updatedAt": 1790846400123 }
  },
  "userIds": [1]
}
```

Клиент:

- переписка есть в загруженном списке → применяет `positionUpdatedAt = message.createdAt` и `lastMessage` по [правилам слияния](#слияние-переписки-на-клиенте) и при необходимости перемещает переписку на место по сортировке (наверх);
- переписки нет в списке (новая или ещё не прогружена пагинацией) → клиент запрашивает её через [`/conversations/getBatch`](#conversationsgetbatch), сливает ответ с данными события по тем же правилам и вставляет переписку на место по сортировке. События, пришедшие по той же переписке, пока запрос в полёте, копятся и применяются после ответа; несколько неизвестных переписок можно запросить одним вызовом.

## Редактирование сообщения (`/messages/edit`)

`Message::edit()` выполняется в транзакции и, как `send()`, сначала блокирует строку переписки:

```sql
SELECT c.last_message_id FROM messages m JOIN conversations c ON c.id = m.conversation_id
WHERE m.id = ? FOR UPDATE OF c
```

Под блокировкой новое сообщение не может стать последним до коммита, поэтому проверка «последнее ли редактируемое сообщение» (`last_message_id = messageId`) не устаревает. Затем `UPDATE messages` (`updated_at = CURRENT_TIMESTAMP(3)`) и, если сообщение последнее:

```sql
UPDATE conversations c JOIN messages m ON m.id = c.last_message_id
SET c.updated_info_at = m.updated_at
WHERE c.last_message_id = ?
```

`updated_info_at` берётся из строки отредактированного сообщения (то есть равен `message.updatedAt`). `position_updated_at` не меняется: правка не поднимает чат.

Блокировка вместо условного `UPDATE … AND last_message_id = ?` с проверкой `rowCount()`: MySQL считает в `rowCount()` только реально изменённые строки, и две правки в одну миллисекунду (равный `updated_at`) дали бы ложное «не последнее». Порядок блокировок тот же, что в `send()` (сначала переписка), — взаимных блокировок нет.

Правка не последнего сообщения и правка без изменения текста переписку не меняют.

### Ответ `/messages/edit`

| Поле          | Тип                  | Описание |
|---------------|----------------------|----------|
| `message`     | Message              | Как сейчас |
| `lastMessage` | LastMessage          | Только если отредактировано последнее сообщение переписки; иначе поля нет |

Отдельно `conversationId`, `positionUpdatedAt` и `updatedInfoAt` переписки не отдаются — они равны `message.conversationId`, `message.createdAt` и `message.updatedAt`.

### WS-событие `messages` / `edited`

В `body` добавляется опциональное поле `lastMessage` (LastMessage) — по тем же правилам, что в ответе. Клиент применяет его, только если переписка уже есть в загруженном списке, — по [правилам слияния](#слияние-переписки-на-клиенте) с `positionUpdatedAt = message.createdAt`. `updatedInfoAt` переписки по этому событию не меняется: событие не несёт остальных полей информации (см. правило 3). Переписку, которой нет в списке, по этому событию не загружает.

Порядок доставки `received` и `edited` не гарантирован (это разные HTTP-запросы). Правила слияния покрывают оба случая:

- `edited(M15)` пришло после `received(M16)` — позиция события `(T15, 15)` меньше локальной `(T16, 16)`, `messageId` не совпадает → превью не откатывается на M15;
- `edited(M16)` пришло раньше `received(M16)` — позиция события `(T16, 16)` больше локальной → переписка поднимается с уже отредактированным текстом; последующее `received(M16)` имеет равную позицию и меньший `lastMessage.updatedAt` → ничего не меняет.

## Слияние переписки на клиенте

Общее правило [синхронизации](../api/WebSocket.md#синхронизация) («побеждает версия с большим `updatedAt`») для Conversation не подходит: у неё две независимые версии, а события `messages` / `received` и `edited` несут не полный объект, а только позицию и `lastMessage`. Поэтому для Conversation правило заменяется тремя, которые клиент применяет по порядку к каждой пришедшей версии переписки — из `getList`, `getBatch`, `createSingle`, `conversations` / `singleCreated`, `messages` / `received` и `edited`:

1. **Позиция.** Версия позиции — пара `(positionUpdatedAt, lastMessage.messageId)`, сравнивается лексикографически; у `lastMessage: null` — `messageId = 0`. Если пришедшая пара **строго больше** локальной — взять `positionUpdatedAt` и `lastMessage` целиком и переместить переписку по сортировке. `messageId` в паре нужен для сообщений, отправленных в одну миллисекунду: id внутри переписки растут строго, поэтому второе сообщение не отбрасывается как «не более новое».
2. **Текст последнего сообщения.** Если после шага 1 `lastMessage.messageId` пришедшей версии совпадает с локальным, а `lastMessage.updatedAt` больше локального — взять `lastMessage`. Так правка применяется только к тому же сообщению и не откатывается более старой правкой.
3. **Информация.** Только для полного объекта Conversation (REST, `singleCreated`): если `updatedInfoAt` больше локального — взять `memberIds`, `name`, `avatar`, `opponentUserId`, `updatedInfoAt`. Частичные события (`received`, `edited`) `updatedInfoAt` не трогают: иначе локальная версия «обогнала» бы не пришедшие изменения названия или участников, и следующий REST-ответ с тем же `updatedInfoAt` их бы не применил.

Применение идемпотентно: при повторе той же версии ни одно правило не срабатывает.

## `/conversations/getList`

Сортировка по умолчанию: по убыванию `positionUpdatedAt`, при равенстве — по убыванию `conversationId` (детерминированный порядок для пагинации). С фильтром `updatedInfoAfter` сортировка своя, см. [сценарий 2](#сценарий-2-фоновое-обновление-метаинформации-updatedinfoafter).

Новые параметры:

| Поле                   | Тип | Обязательное | Описание |
|------------------------|-----|--------------|----------|
| `positionUpdatedAfter` | Int | Нет          | Только переписки с `positionUpdatedAt` строго больше значения (мс). Сортировка и пагинация (`offset` / `count`) — как без фильтра |
| `updatedInfoAfter`     | Int | Нет          | Только переписки, у которых `updatedInfoAt` строго больше значения (мс) — или, если передан `updatedInfoAfterId`, пара `(updatedInfoAt, conversationId)` строго больше `(updatedInfoAfter, updatedInfoAfterId)`. Сортировка — по **возрастанию** `updatedInfoAt`, затем `conversationId`. `offset` с ним не используется |
| `updatedInfoAfterId`   | Int | Нет          | Курсор следующей страницы для `updatedInfoAfter`: `conversationId` последней полученной переписки. Только вместе с `updatedInfoAfter` |

- `hasNext` считается в пределах фильтра.
- Ошибка `1004`:
  - переданы оба фильтра `positionUpdatedAfter` и `updatedInfoAfter` (сценарии у них разные, см. ниже; комбинация ничему не соответствует);
  - `updatedInfoAfter` передан вместе с `offset`;
  - `updatedInfoAfterId` передан без `updatedInfoAfter`;
  - невалидное значение (не целое неотрицательное число).

Условие курсора в SQL: `c.updated_info_at > ? OR (c.updated_info_at = ? AND c.id > ?)`, `ORDER BY c.updated_info_at, c.id`.

### Сценарий 1: догоняющая синхронизация после оффлайна (`positionUpdatedAfter`)

Клиент хранит `maxPositionUpdatedAt` — максимальный `positionUpdatedAt` среди полученных от сервера переписок (из ответов и WS-событий; часы устройства не используются).

1. При возврате в онлайн (после переподключения WS) клиент запрашивает `getList` с `positionUpdatedAfter = maxPositionUpdatedAt` (минус запас, см. [Порядок коммитов](#граничные-случаи-и-риски)) и `offset = 0`.
2. `hasNext: false` → все поднявшиеся переписки получены: клиент сливает их по [правилам](#слияние-переписки-на-клиенте), вставляет новые и пересортировывает список. Остальной список остаётся валидным.
3. `hasNext: true` → поднялось больше одной страницы. Клиент оставляет только полученную страницу, остальные переписки инвалидирует и удаляет из списка; дальнейшая подгрузка — обычной пагинацией без `positionUpdatedAfter`, начиная с `offset` = размеру полученной страницы. Это корректно: при одинаковой сортировке отфильтрованная страница совпадает с началом полного списка.

### Сценарий 2: фоновое обновление метаинформации (`updatedInfoAfter`)

Клиент хранит `maxUpdatedInfoAt` — максимальный `updatedInfoAt` из полных объектов Conversation (REST, `singleCreated`).

1. Первый запрос — `updatedInfoAfter = maxUpdatedInfoAt` минус запас, без `updatedInfoAfterId`.
2. Пока `hasNext: true` — следующий запрос с курсором последней полученной переписки: `updatedInfoAfter` = её `updatedInfoAt`, `updatedInfoAfterId` = её `conversationId`.
3. После последней страницы `maxUpdatedInfoAt` = максимум из полученного.

Сортировка по возрастанию `updatedInfoAt` с курсором вместо `offset` нужна, чтобы листание не теряло записи: переписка, изменившаяся во время листания, уходит в конец выдачи и будет получена позже, а не сдвигает страницы. При сортировке по позиции с `offset` поднявшаяся переписка сдвигала бы страницы, одна запись пропускалась бы, а `maxUpdatedInfoAt` уходил бы за неё — правка терялась бы насовсем.

Полученные переписки сливаются только с **уже загруженными** на клиенте — по всем [правилам слияния](#слияние-переписки-на-клиенте), включая позицию: если переписка за это время поднялась, она и перемещается, а не только получает новый `lastMessage` на старом месте. Новые в список не добавляются — иначе переписка с условной 1000-й позиции появилась бы в списке, где прогружено 200.

Запросы сценариев 1 и 2 независимы: после оффлайна клиент выполняет оба — поднявшиеся переписки приходят через `positionUpdatedAfter`, правки без перемещения — через `updatedInfoAfter`.

## `/conversations/getBatch`

`GET /conversations/getBatch` — переписки по списку id. Нужен, когда клиент узнал о переписке, которой у него нет: `messages` / `received` по чату, не прогруженному пагинацией (или созданному на другом устройстве), в дальнейшем — пуши и диплинки. Устроен как [`/users/getBatch`](../api/methods/Users.md#get-usersgetbatch--пользователи-по-списку-id). Требует активной сессии.

### Параметры запроса

| Поле              | Тип   | Обязательное | Описание |
|-------------------|-------|--------------|----------|
| `conversationIds` | Int[] | Да           | Идентификаторы переписок, от 1 до 100 штук. Через запятую (`conversationIds=1,2,3`) или массивом (`conversationIds[]=1&conversationIds[]=2`). Повторы игнорируются |

### Успешный ответ

| Поле            | Тип            | Описание |
|-----------------|----------------|----------|
| `conversations` | Conversation[] | Найденные переписки — в порядке `conversationIds` (не в порядке списка чатов: место в списке клиент определяет по `positionUpdatedAt`) |
| `notFoundIds`   | Int[]          | Идентификаторы, для которых переписка не найдена **или текущий пользователь в ней не состоит**, — в порядке `conversationIds`. Чужие переписки неотличимы от несуществующих, чтобы метод не раскрывал их существование |

```json
{
  "status": 1,
  "response": {
    "conversations": [
      {
        "conversationId": 7,
        "type": "single",
        "memberIds": [1, 5],
        "name": null,
        "avatar": null,
        "opponentUserId": 5,
        "lastMessage": { "messageId": 42, "type": "text", "text": "Привет!", "updatedAt": 1790846400123 },
        "positionUpdatedAt": 1790846400123,
        "updatedInfoAt": 1790846000456
      }
    ],
    "notFoundIds": [999]
  },
  "actions": [
    { "type": "users", "data": { "userIds": [1, 5] } }
  ]
}
```

Также отдаётся [action `users`](../api/General.md#action-users) с участниками всех переписок в выдаче (не отдаётся, если список пуст).

### Ошибки

| Код  | Описание |
|------|----------|
| 1004 | `conversationIds` не передан, пуст, содержит больше 100 элементов или элемент, не являющийся целым неотрицательным числом |
| 2003 | Пользователь не авторизован |

### Реализация

`Conversation::findByIds(array $ids, int $viewerId)` — общий `SELECT` с `JOIN conversation_members m ON m.conversation_id = c.id AND m.user_id = ?` и `WHERE c.id IN (...)`: членство проверяется тем же JOIN-ом, отдельного запроса не нужно. Порядок `conversationIds` восстанавливается в PHP. Список id разбирается общим хелпером `Request::requireIdList('conversationIds', 100)` из `core/Request.php` — тем же, что в `users/getBatch.php`, поэтому правила (разделители, лимит, повторы) совпадают.

## Граничные случаи и риски

- **Сдвиг пагинации.** Пагинация списка по `offset`: если во время листания переписка снизу поднялась наверх, страницы сдвигаются, и на следующей странице повторится уже полученная переписка. Пропусков при этом нет — переписки в сортировке по позиции только поднимаются; пропуск возможен лишь при выходе из переписки или её удалении (их пока нет). Клиент дедуплицирует по `conversationId` и сливает версии по [правилам](#слияние-переписки-на-клиенте); поднявшуюся переписку он и так получает WS-событием. Курсорная пагинация (`before = positionUpdatedAt + conversationId`) убрала бы и дубли — откладываем, пока хватает `offset`. Для `updatedInfoAfter` курсор обязателен, см. [сценарий 2](#сценарий-2-фоновое-обновление-метаинформации-updatedinfoafter).
- **Порядок коммитов.** Время сообщения фиксируется до коммита транзакции. Если переписка A получила время T, а коммит произошёл после того, как клиент уже увидел переписку B с временем T+1, запрос `positionUpdatedAfter = T+1` переписку A не вернёт. Окно — длительность транзакции отправки (миллисекунды). Смягчение на клиенте: передавать `maxPositionUpdatedAt` минус небольшой запас (например, 5 секунд) — лишние переписки в ответе безвредны, слияние идемпотентно. То же для первого запроса с `updatedInfoAfter`.
- **Порядок WS-событий.** События могут прийти позже ответа `getList` с более свежими данными, а `received` и `edited` — в любом порядке между собой; поэтому клиент применяет всё через [правила слияния](#слияние-переписки-на-клиенте) и не откатывает состояние назад.
- **Удаление сообщений** (пока нет): при удалении последнего сообщения `last_message_id` станет `NULL` (FK) — понадобится пересчёт на предыдущее сообщение, решение о `positionUpdatedAt` (не опускать чат вниз) и событие. Проектируется вместе с удалением.

## Открытые вопросы

- **`senderId` в LastMessage** для превью групповых чатов — когда появятся групповые чаты на клиенте.

## Реализация

- Миграции: `db/010_datetime_ms.sql`, `db/011_conversations_last_message.sql`.
- `core/DB.php` — `time_zone = '+00:00'` при подключении, хелперы `DB::ms()` (колонка → мс) и `DB::fromMs()` (параметр в мс → `DATETIME`; деление в `DECIMAL`, потому что параметры PDO приходят строками и в `DOUBLE` миллисекунды могли бы округлиться).
- `core/Conversation.php` — выборка с LastMessage, `findListByMember()` с `positionUpdatedAfter`, `findInfoUpdatedByMember()` с курсором, `findByIds()`; `core/Message.php` — `send()`, `edit()`, `toLastMessage()`, `lastMessageFromRow()`; `core/Request.php` — `optionalUint()`.
- Методы: `conversations/getList.php`, `conversations/getBatch.php`, `messages/send.php`, `messages/edit.php`, `admin/sendMessage.php` (то же событие `received`).
- Тесты: `tests/unit/ConversationTest.php`, `MessageTest.php`, `RequestTest.php`; `tests/api/ConversationsTest.php`, `MessagesTest.php`, `WsEventsTest.php`, `AdminTest.php`.
