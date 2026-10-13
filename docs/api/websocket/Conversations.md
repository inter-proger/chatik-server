# WebSocket: `conversations` — Переписки

[← WebSocket](../WebSocket.md) · [Документация API](../../../API_Documentation.md)

## `conversations` / `singleCreated` — создана личная переписка

Сервер → клиент. Приходит всем участникам новой личной переписки (во все их соединения, включая соединения создателя), когда переписка создана методом [`/conversations/createSingle`](../methods/Conversations.md#post-conversationscreatesingle--создание-личной-переписки). Если метод вернул уже существующую переписку, событие не отправляется.

| Поле `body`    | Тип          | Описание                                                                                     |
|----------------|--------------|----------------------------------------------------------------------------------------------|
| `conversation` | Conversation | Объект [Conversation](../Objects.md#объект-conversation) созданной переписки — с точки зрения получателя (`opponentUserId` — собеседник получателя) |

`userIds` — участники переписки (`memberIds`).

```json
{
  "uid": "1e5f7a9c-3b2d-4c6e-8a0f-4d6b8c0e2a4f",
  "group": "conversations",
  "event": "singleCreated",
  "body": {
    "conversation": {
      "conversationId": 1,
      "type": "single",
      "memberIds": [1, 2],
      "name": null,
      "avatar": null,
      "opponentUserId": 1,
      "lastMessage": null,
      "positionUpdatedAt": 1790846400123,
      "updatedInfoAt": 1790846400123
    }
  },
  "userIds": [1, 2]
}
```
