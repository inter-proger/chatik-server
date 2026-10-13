# WebSocket: `messages` — Сообщения

[← WebSocket](../WebSocket.md) · [Документация API](../../../API_Documentation.md)

## `messages` / `received` — новое сообщение

Сервер → клиент. Приходит всем участникам переписки (во все их соединения, включая соединения отправителя), когда сообщение отправлено методом [`/messages/send`](../methods/Messages.md#post-messagessend--отправка-сообщения).

| Поле `body` | Тип     | Описание                                              |
|-------------|---------|-------------------------------------------------------|
| `message`   | Message | Объект [Message](../Objects.md#объект-message) нового сообщения     |

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
      "createdAt": 1790846400,
      "updatedAt": 1790846400
    }
  },
  "userIds": [1]
}
```

Отправитель получает событие и в том соединении, с устройства которого отправил сообщение: сообщение у него уже есть из ответа `/messages/send`, и по правилам [синхронизации](../WebSocket.md#синхронизация) повторное применение ничего не меняет. Событие может прийти и раньше ответа — локальное неподтверждённое сообщение клиент находит по `clientId` (вместе с `senderId`) и заменяет серверным. При повторе `/messages/send` с тем же `clientId` событие повторно не рассылается.

## `messages` / `edited` — сообщение отредактировано

Сервер → клиент. Приходит всем участникам переписки (во все их соединения, включая соединения автора), когда сообщение изменено методом [`/messages/edit`](../methods/Messages.md#post-messagesedit--редактирование-сообщения). Если текст не изменился, событие не отправляется.

| Поле `body` | Тип     | Описание                                                   |
|-------------|---------|------------------------------------------------------------|
| `message`   | Message | Обновлённый объект [Message](../Objects.md#объект-message)               |

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
      "createdAt": 1790846400,
      "updatedAt": 1790846520
    }
  },
  "userIds": [1]
}
```
