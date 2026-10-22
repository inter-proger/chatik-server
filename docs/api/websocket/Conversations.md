# WebSocket: `conversations` — Переписки

[← WebSocket](../WebSocket.md) · [Документация API](../../../API_Documentation.md)

## `conversations` / `singleCreated` — создана личная переписка

Сервер → клиент. Приходит всем участникам новой личной переписки (во все их соединения, включая соединения создателя), когда переписка создана методом [`/conversations/createSingle`](../methods/Conversations.md#post-conversationscreatesingle--создание-личной-переписки). Если метод вернул уже существующую переписку, событие не отправляется.

| Поле `body`    | Тип          | Описание                                                                                     |
|----------------|--------------|----------------------------------------------------------------------------------------------|
| `conversation` | Conversation | Объект [Conversation](../Objects.md#объект-conversation) созданной переписки — с точки зрения получателя (`opponentUserId` — собеседник получателя) |

`userIds` — участники переписки (`memberIds`).

Создание — изменение переписок обоих участников: `conversation.changeVersion` — новая [версия](../Objects.md#версия-пользователя) получателя и версия события для [курсора синхронизации](../WebSocket.md#курсор-синхронизации).

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
      "updatedInfoAt": 1790846400123,
      "unreadCount": 0,
      "lastReadMessageId": 0,
      "changeVersion": 57
    }
  },
  "userIds": [1, 2]
}
```

## `conversations` / `read` — переписка прочитана

Сервер → клиент. Приходит во все соединения пользователя, вызвавшего [`/conversations/markRead`](../methods/Conversations.md#post-conversationsmarkread--отметка-прочтения), — в том числе в соединение устройства, с которого прочитали (повторное применение отсекается версиями). Другим участникам вместо него приходит [`conversations` / `memberRead`](#conversations--memberread--участник-прочитал-переписку). Если `markRead` ничего не изменил (повтор или более старое сообщение), события нет.

| Поле `body` | Тип      | Описание |
|-------------|----------|----------|
| `readInfo`  | ReadInfo | Новое состояние прочтения переписки и общий счётчик — объект [ReadInfo](../Objects.md#объект-readinfo); `changeVersion` — версия этого прочтения |

`userIds` нет.

```json
{
  "uid": "9b1d3f5a-7c9e-4b2d-8f4a-6c8e0a2b4d6f",
  "group": "conversations",
  "event": "read",
  "body": {
    "readInfo": {
      "conversationId": 1,
      "lastReadMessageId": 15,
      "unreadCount": 0,
      "totalUnreadCount": 3,
      "changeVersion": 118
    }
  }
}
```

Клиент применяет поля переписки и общий счётчик независимо, каждый по своей локальной версии, сравнивая их с одним `changeVersion` (правила — в [ReadInfo](../Objects.md#объект-readinfo)). Переписку, которой нет в загруженном списке, по этому событию не загружают, но общий счётчик применяют. Курсор синхронизации событие двигает по [правилу непрерывности](../WebSocket.md#курсор-синхронизации).

## `conversations` / `memberRead` — участник прочитал переписку

Сервер → клиент. Приходит во все соединения остальных участников переписки, когда участник сдвинул указатель прочтения методом [`/conversations/markRead`](../methods/Conversations.md#post-conversationsmarkread--отметка-прочтения). Если `markRead` ничего не изменил, события нет. Самому прочитавшему не отправляется — у него [`conversations` / `read`](#conversations--read--переписка-прочитана). Отправка сообщения тоже сдвигает указатель отправителя, но этого события не порождает: клиент сдвигает указатель по [`messages` / `received`](Messages.md#messages--received--новое-сообщение).

| Поле `body`         | Тип | Описание |
|---------------------|-----|----------|
| `conversationId`    | Int | Идентификатор переписки |
| `userId`            | Int | Кто прочитал |
| `lastReadMessageId` | Int | Новый указатель прочтения участника |

`userIds` нет: `userId` — участник переписки, клиент уже знает его из `memberIds` переписки.

```json
{
  "uid": "3c5e7a9b-1d2f-4a6c-8e0b-2d4f6a8c0e1b",
  "group": "conversations",
  "event": "memberRead",
  "body": {
    "conversationId": 1,
    "userId": 2,
    "lastReadMessageId": 15
  }
}
```

Сервер рассылает событие независимо от того, открыта ли переписка у получателя. Клиент применяет его, если переписка открыта или её мета уже сохранена, — по максимуму с локальным указателем участника (правила — в [`/conversations/getMembersReadMeta`](../methods/Conversations.md#правила-клиента)). Событие не несёт `changeVersion` и не участвует в [курсоре синхронизации](../WebSocket.md#курсор-синхронизации): версия получателя при чужом прочтении не растёт.
