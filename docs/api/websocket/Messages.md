# WebSocket: `messages` — Сообщения

[← WebSocket](../WebSocket.md) · [Документация API](../README.md)

## `messages` / `received` — новое сообщение

Сервер → клиент. Приходит всем участникам переписки (во все их соединения, включая соединения отправителя), когда сообщение отправлено методом [`/messages/send`](../methods/Messages.md#post-messagessend--отправка-сообщения).

| Поле `body`   | Тип         | Описание                                              |
|---------------|-------------|-------------------------------------------------------|
| `message`     | Message     | Объект [Message](../Objects.md#объект-message) нового сообщения     |
| `lastMessage` | LastMessage | Новый [LastMessage](../Objects.md#объект-lastmessage) переписки `message.conversationId` |
| `readInfo`    | ReadInfo    | Состояние прочтения переписки для **получателя** события — объект [ReadInfo](../Objects.md#объект-readinfo); `changeVersion` — версия этого изменения |

Переписка поднимается наверх списка: её новый `positionUpdatedAt` равен `message.createdAt` (отдельным полем не дублируется).

`readInfo` у каждого получателя свой: у отправителя `unreadCount = 0` и `lastReadMessageId = message.messageId` (своё сообщение отмечает прочитанной переписку до него), у остальных — увеличенный на 1 `unreadCount`. Клиент применяет его по правилам [ReadInfo](../Objects.md#объект-readinfo), курсор синхронизации — по [правилу непрерывности](../WebSocket.md#курсор-синхронизации).

`userIds` — отправитель сообщения (`senderId`).

```json
{
  "uid": "7d2b9e4f-1a3c-4e5d-8f6a-2b4c6d8e0a1b",
  "group": "messages",
  "event": "received",
  "body": {
    "message": {
      "messageId": 15,
      "clientId": "6f1c2a9e-4b7d-4e3a-9c51-0d2f8b6a7e14",
      "conversationId": 1,
      "blockId": 2,
      "senderId": 1,
      "type": "text",
      "text": "Привет!",
      "isEdited": false,
      "createdAt": 1790846400123,
      "updatedAt": 1790846400123
    },
    "lastMessage": {
      "messageId": 15,
      "type": "text",
      "text": "Привет!",
      "updatedAt": 1790846400123
    },
    "readInfo": {
      "conversationId": 1,
      "lastReadMessageId": 14,
      "unreadCount": 1,
      "totalUnreadCount": 3,
      "changeVersion": 118
    }
  },
  "userIds": [1]
}
```

Клиент применяет позицию и `lastMessage` по [правилам слияния переписки](../WebSocket.md#слияние-переписки):

- переписка есть в загруженном списке → обновить и при необходимости переместить её наверх;
- переписки нет в списке (новая или ещё не прогружена пагинацией) → запросить её через [`/conversations/getBatch`](../methods/Conversations.md#get-conversationsgetbatch--переписки-по-списку-id), слить ответ с данными события и вставить на место по сортировке. События по той же переписке, пришедшие, пока запрос в полёте, копятся и применяются после ответа; несколько неизвестных переписок можно запросить одним вызовом.

Отправитель получает событие и в том соединении, с устройства которого отправил сообщение: сообщение у него уже есть из ответа `/messages/send`, и по правилам [синхронизации](../WebSocket.md#синхронизация) повторное применение ничего не меняет. Событие может прийти и раньше ответа — локальное неподтверждённое сообщение клиент находит по `clientId` (вместе с `senderId`) и заменяет серверным. При повторе `/messages/send` с тем же `clientId` событие повторно не рассылается.

## `messages` / `edited` — сообщение отредактировано

Сервер → клиент. Приходит всем участникам переписки (во все их соединения, включая соединения автора), когда сообщение изменено методом [`/messages/edit`](../methods/Messages.md#post-messagesedit--редактирование-сообщения). Если текст не изменился, событие не отправляется.

| Поле `body`   | Тип         | Описание                                                   |
|---------------|-------------|------------------------------------------------------------|
| `message`     | Message     | Обновлённый объект [Message](../Objects.md#объект-message)               |
| `lastMessage` | LastMessage | Только если отредактировано последнее сообщение переписки: её новый [LastMessage](../Objects.md#объект-lastmessage); иначе поля нет |
| `changeVersion` | Int       | Только вместе с `lastMessage`: новая [версия](../Objects.md#версия-пользователя) **получателя** — правка последнего сообщения меняет информацию переписки. Нужна только [курсору синхронизации](../WebSocket.md#курсор-синхронизации); поля переписки по ней не сливаются |

Прочтение правка не меняет. Если `lastMessage` есть и переписка уже загружена в список, клиент применяет его по [правилам слияния переписки](../WebSocket.md#слияние-переписки) с `positionUpdatedAt = message.createdAt`; `updatedInfoAt` переписки по событию не меняется. Переписку, которой нет в списке, по этому событию не загружают. Порядок доставки `received` и `edited` не гарантирован — правила слияния учитывают оба порядка.

`userIds` — отправитель сообщения (`senderId`).

```json
{
  "uid": "4a6c8e0b-2d4f-4b6d-9e1a-3c5e7a9b1d3f",
  "group": "messages",
  "event": "edited",
  "body": {
    "message": {
      "messageId": 15,
      "clientId": "6f1c2a9e-4b7d-4e3a-9c51-0d2f8b6a7e14",
      "conversationId": 1,
      "blockId": 2,
      "senderId": 1,
      "type": "text",
      "text": "Привет! Как дела?",
      "isEdited": true,
      "createdAt": 1790846400123,
      "updatedAt": 1790846520456
    },
    "lastMessage": {
      "messageId": 15,
      "type": "text",
      "text": "Привет! Как дела?",
      "updatedAt": 1790846520456
    },
    "changeVersion": 119
  },
  "userIds": [1]
}
```
