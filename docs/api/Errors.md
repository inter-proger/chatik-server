# Коды ошибок

[← Документация API](README.md)

| Код  | Описание                                              | Методы                        |
|------|-------------------------------------------------------|-------------------------------|
| 1001 | Невалидный `login` (в т. ч. длиннее 64 символов)      | `/auth/signup`, `/auth/login` |
| 1002 | Пароль не соответствует требованиям                   | `/auth/signup`, `/auth/login` |
| 1003 | Невалидное `name`: имя пользователя — не только буквы, цифры, `-`, `_` и пробелы или длиннее 128 символов; название группы — пустое после обрезки пробелов или длиннее 128 символов | `/auth/signup`, `/groups/create` |
| 1004 | Невалидные параметры запроса                          | `/users/getList`, `/users/getBatch`, `/conversations/getList`, `/conversations/getBatch`, `/messages/getList`, `/messages/getBlockBatch`, `/groups/addMembers`, `/groups/removeMembers`, WS `auth` / `connect` |
| 1005 | Невалидный текст сообщения: пустой (после обрезки пробелов) или длиннее 4096 символов | `/messages/send`, `/messages/edit` |
| 1006 | Невалидный `clientId`: не передан, пустой, длиннее 64 символов или содержит символы кроме `A–Z`, `a–z`, `0–9`, `-`, `_` | `/messages/send` |
| 1007 | Невалидное `description` группы: длиннее 1024 символов | `/groups/create` |
| 2001 | Логин уже занят                                       | `/auth/signup`                |
| 2002 | Неверный логин или пароль                             | `/auth/login`                 |
| 2003 | Пользователь не авторизован                           | `/auth/logout`, `/profile/get`, `/profile/setAvatar`, `/profile/removeAvatar`, `/files/uploadImage`, `/users/getList`, `/users/getBatch`, `/conversations/createSingle`, `/conversations/getList`, `/conversations/getBatch`, `/messages/getList`, `/messages/getBlock`, `/messages/getBlockBatch`, `/messages/send`, `/messages/edit`, `/global/getWebsocketToken`, `/conversations/markRead`, `/conversations/getMembersReadMeta`, `/groups/*` |
| 2004 | Токен подключения к WebSocket не найден, истёк, уже использован или выдан другому пользователю | WS `auth` / `connect` |
| 3001 | Файл не передан                                       | `/files/uploadImage`          |
| 3002 | Файл слишком большой (максимум 10 МБ или 25 мегапикселей) | `/files/uploadImage`      |
| 3003 | Файл не является изображением                         | `/files/uploadImage`, `/profile/setAvatar`, `/groups/create` |
| 3004 | Файл не найден / невалидный `fileId`                 | `/files/get`, `/profile/setAvatar`, `/groups/create` (`avatarFileId`, в том числе файл другого пользователя) |
| 3005 | Ошибка сохранения файла                               | `/files/uploadImage`          |
| 4001 | Пользователь не найден / невалидный `userId`          | `/conversations/createSingle`, `/groups/addMembers` (в `notAddedUsersInfo`) |
| 5001 | Нельзя создать личную переписку с самим собой         | `/conversations/createSingle` |
| 5002 | Переписка не найдена, невалидный `conversationId` или пользователь в ней не состоит | `/messages/getList`, `/messages/send`, `/conversations/markRead`, `/conversations/getMembersReadMeta`, `/groups/*` (кроме `create`) |
| 5003 | Переписка не групповая                                | `/groups/*` (кроме `create`) |
| 5004 | Действие доступно только владельцу группы             | `/groups/addMembers`, `/groups/removeMembers`, `/groups/changeOwner` |
| 5005 | Достигнут лимит участников группы (100)               | `/groups/addMembers` (в `notAddedUsersInfo`) |
| 5006 | Пользователь уже участник группы                      | `/groups/addMembers` (в `notAddedUsersInfo`) |
| 5007 | Пользователь не участник группы                       | `/groups/removeMembers` (в `notRemovedUsersInfo`), `/groups/changeOwner` |
| 5008 | Действие нельзя применить к владельцу: удалить владельца, передать владение самому себе | `/groups/removeMembers` (в `notRemovedUsersInfo`), `/groups/changeOwner` |
| 6001 | Сообщение не найдено, невалидный `messageId` или сообщение из переписки, в которой пользователь не состоит | `/messages/edit` |
| 6002 | Блок сообщений не найден, невалидный `blockId` или блок из другой переписки | `/messages/getList`, `/messages/getBlock` |
| 6003 | Нельзя редактировать чужое сообщение                  | `/messages/edit`              |
| 6004 | `clientId` уже использован для сообщения текущего пользователя в другой переписке | `/messages/send` |
| 404  | Неизвестный метод (путь не найден; сравнение с учётом регистра). HTTP-статус ответа — 404 | Любой путь, которого нет среди методов |
| 9001 | Внутренняя ошибка сервера                             | Все методы, WS `auth` / `connect` |
