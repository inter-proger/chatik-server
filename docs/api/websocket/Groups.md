# WebSocket: `groups` — Групповые переписки

[← WebSocket](../WebSocket.md) · [Документация API](../README.md)

События изменения групп: создание, состав и владелец. Тело зависит от получателя:

| Получатель | Что несёт событие | Версия пользователя |
|------------|-------------------|---------------------|
| Создатель (`created`) | Conversation и GroupInfo | `conversation.changeVersion` — в [курсор](../WebSocket.md#курсор-синхронизации) |
| Добавленный (`membersAdded`) | `conversationId`, `changeVersion` и GroupInfo; Conversation клиент загружает через [`/conversations/getBatch`](../methods/Conversations.md#get-conversationsgetbatch--переписки-по-списку-id) | `changeVersion` — в курсор после успешного `getBatch` |
| Удалённый или вышедший (`membersRemoved`, `memberLeft`) | [RemovedConversation](../Objects.md#объект-removedconversation) и `totalUnreadCount` | `removedConversation.changeVersion` — в курсор |
| Остальные участники | [GroupInfo](../Objects.md#объект-groupinfo) | нет: Conversation не меняется, событие не участвует в курсоре |

GroupInfo применяется по [правилам клиента](../Objects.md#объект-groupinfo): если группа открыта или её GroupInfo сохранена, и только с большей `version`. Поле `userIds` перечисляет только пользователей, которых изменение касается (добавленные, удалённые, вышедший, новый владелец), а не весь состав: остальных участников из `groupInfo` клиент загружает, когда показывает группу, — как по action `users` ответа [`/groups/getInfo`](../methods/Groups.md#get-groupsgetinfo--состав-группы).

## `groups` / `created` — группа создана

Сервер → клиент. Приходит во все соединения создателя (включая устройство, с которого создана группа — повтор отсекается версиями), когда группа создана методом [`/groups/create`](../methods/Groups.md#post-groupscreate--создание-группы).

| Поле `body`    | Тип          | Описание |
|----------------|--------------|----------|
| `conversation` | Conversation | Созданная группа — объект [Conversation](../Objects.md#объект-conversation) |
| `groupInfo`    | GroupInfo    | Состав: создатель с ролью `owner` |

`userIds` — `[создатель]`. `conversation.changeVersion` — версия события для курсора синхронизации; переписка сливается по [правилам слияния](../WebSocket.md#слияние-переписки).

```json
{
  "uid": "2a4c6e8f-0b1d-4f3a-9c5e-7a9b1d3f5c7e",
  "group": "groups",
  "event": "created",
  "body": {
    "conversation": {
      "conversationId": 12,
      "type": "group",
      "memberIds": null,
      "name": "Команда",
      "description": null,
      "avatar": null,
      "opponentUserId": null,
      "lastMessage": null,
      "positionUpdatedAt": 1790846400123,
      "updatedInfoAt": 1790846400123,
      "unreadCount": 0,
      "lastReadMessageId": 0,
      "changeVersion": 57
    },
    "groupInfo": {
      "conversationId": 12,
      "version": 1,
      "members": [{ "userId": 1, "role": "owner", "joinedAfterMessageId": 0 }]
    }
  },
  "userIds": [1]
}
```

## `groups` / `membersAdded` — участники добавлены

Сервер → клиент. Приходит всем участникам после изменения — старым и добавленным, — когда метод [`/groups/addMembers`](../methods/Groups.md#post-groupsaddmembers--добавление-участников) кого-то добавил.

| Поле `body`      | Тип         | Описание |
|------------------|-------------|----------|
| `conversationId` | Int         | Группа |
| `addedUserIds`   | Int[]       | Добавленные |
| `groupInfo`      | GroupInfo   | Состав после изменения |
| `changeVersion`  | Int \| null | Добавленному — его новая [версия](../Objects.md#версия-пользователя); остальным — `null` |

`userIds` — `addedUserIds`.

```json
{
  "uid": "4c6e8a0b-2d3f-4b5c-8e7a-9c1e3a5b7d9f",
  "group": "groups",
  "event": "membersAdded",
  "body": {
    "conversationId": 12,
    "addedUserIds": [5],
    "groupInfo": {
      "conversationId": 12,
      "version": 2,
      "members": [
        { "userId": 1, "role": "owner", "joinedAfterMessageId": 0 },
        { "userId": 5, "role": "member", "joinedAfterMessageId": 318 }
      ]
    },
    "changeVersion": 74
  },
  "userIds": [5]
}
```

**Добавленный** (`changeVersion` не `null`) — для него это новая переписка:

1. Сохраняет GroupInfo из события.
2. Запрашивает [`/conversations/getBatch`](../methods/Conversations.md#get-conversationsgetbatch--переписки-по-списку-id) по `conversationId` и вставляет группу в список по позиции — у него это время вступления, то есть наверх.
3. Только после успешного ответа добавляет `changeVersion` в курсор синхронизации по [правилу непрерывности](../WebSocket.md#курсор-синхронизации).

Если `getBatch` не удался (оффлайн), клиент запоминает `conversationId` в списке ожидающих `pendingConversationIds` (хранится локально) и повторяет `getBatch` после каждого переподключения, вместе с синхронизацией списка. Пока запрос не удался, версия в курсор не попадает. Полагаться на синхронизацию списка здесь нельзя: `positionUpdatedAfter` может уже уйти вперёд по событиям других переписок, а `changedAfter` новые переписки в список не добавляет. Если повторный `getBatch` вернул группу в `notFoundIds` (её успели удалить), ссылка снимается, версия попадает в курсор.

**Остальные** (`changeVersion: null`) применяют GroupInfo по [правилам клиента](../Objects.md#объект-groupinfo).

## `groups` / `membersRemoved` — участники удалены

Сервер → клиент. Приходит оставшимся участникам и удалённым (во все их соединения), когда метод [`/groups/removeMembers`](../methods/Groups.md#post-groupsremovemembers--удаление-участников) кого-то удалил.

| Поле `body`           | Тип                         | Описание |
|-----------------------|-----------------------------|----------|
| `conversationId`      | Int                         | Группа |
| `removedUserIds`      | Int[]                       | Удалённые |
| `groupInfo`           | GroupInfo \| null           | Оставшимся — состав после изменения; удалённому — `null` |
| `removedConversation` | RemovedConversation \| null | Удалённому — метка с его новой версией; оставшимся — `null` |
| `totalUnreadCount`    | Int \| null                 | Удалённому — общий счётчик после вычета непрочитанных группы, на версии `removedConversation.changeVersion`; оставшимся — `null` |

`userIds` — `removedUserIds`.

Удалённому:

```json
{
  "uid": "6e8a0c2d-4f5b-4d7e-8a9c-1e3a5c7e9b0d",
  "group": "groups",
  "event": "membersRemoved",
  "body": {
    "conversationId": 12,
    "removedUserIds": [5],
    "groupInfo": null,
    "removedConversation": { "conversationId": 12, "changeVersion": 81 },
    "totalUnreadCount": 3
  },
  "userIds": [5]
}
```

Удалённый применяет метку по [правилам RemovedConversation](../Objects.md#объект-removedconversation) — в том числе сохраняет локальную метку, если группы нет в загруженном списке, — версию метки по правилу непрерывности, `totalUnreadCount` — по правилам [ReadInfo](../Objects.md#объект-readinfo). Оставшиеся применяют GroupInfo.

## `groups` / `memberLeft` — участник вышел

Сервер → клиент. Приходит оставшимся участникам и вышедшему (во все его соединения, включая вызвавшее), когда участник вышел методом [`/groups/leave`](../methods/Groups.md#post-groupsleave--выход-из-группы).

| Поле `body`           | Тип                         | Описание |
|-----------------------|-----------------------------|----------|
| `conversationId`      | Int                         | Группа |
| `leftUserId`          | Int                         | Вышедший |
| `groupInfo`           | GroupInfo \| null           | Оставшимся — состав после выхода; вышедшему — `null` |
| `removedConversation` | RemovedConversation \| null | Вышедшему — метка с его новой версией; оставшимся — `null` |
| `totalUnreadCount`    | Int \| null                 | Вышедшему — общий счётчик на версии метки; оставшимся — `null` |

`userIds` — вышедший и, если вышел владелец, новый владелец. Смена владельца видна по ролям в `groupInfo`, отдельного `ownerChanged` нет. Применяется так же, как `membersRemoved`.

```json
{
  "uid": "8a0c2e4f-6b7d-4f9a-8c1e-3a5c7e9b1d2f",
  "group": "groups",
  "event": "memberLeft",
  "body": {
    "conversationId": 12,
    "leftUserId": 1,
    "groupInfo": {
      "conversationId": 12,
      "version": 5,
      "members": [{ "userId": 2, "role": "owner", "joinedAfterMessageId": 318 }]
    },
    "removedConversation": null,
    "totalUnreadCount": null
  },
  "userIds": [1, 2]
}
```

## `groups` / `ownerChanged` — владелец сменился

Сервер → клиент. Приходит всем участникам, когда владение передано методом [`/groups/changeOwner`](../methods/Groups.md#post-groupschangeowner--передача-владения).

| Поле `body`      | Тип       | Описание |
|------------------|-----------|----------|
| `conversationId` | Int       | Группа |
| `newOwnerUserId` | Int       | Новый владелец |
| `groupInfo`      | GroupInfo | Состав после изменения |

`userIds` — `[newOwnerUserId]`. Версии пользователя нет, событие не участвует в курсоре.

```json
{
  "uid": "0c2e4a6b-8d9f-4b1c-8e3a-5c7e9b1d3f4a",
  "group": "groups",
  "event": "ownerChanged",
  "body": {
    "conversationId": 12,
    "newOwnerUserId": 2,
    "groupInfo": {
      "conversationId": 12,
      "version": 4,
      "members": [
        { "userId": 1, "role": "member", "joinedAfterMessageId": 0 },
        { "userId": 2, "role": "owner", "joinedAfterMessageId": 318 }
      ]
    }
  },
  "userIds": [2]
}
```
