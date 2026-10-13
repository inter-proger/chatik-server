# Методы API

[← Документация API](../../API_Documentation.md)

Формат запросов и ответов — в [Общих сведениях](General.md), коды ошибок — в [Кодах ошибок](Errors.md).

## [/global](methods/Global.md) — Старт приложения и WebSocket-токен

- [GET /global/start — Старт работы приложения](methods/Global.md#get-globalstart--старт-работы-приложения)
- [GET /global/getWebsocketToken — Токен подключения к WebSocket](methods/Global.md#get-globalgetwebsockettoken--токен-подключения-к-websocket)

## [/auth](methods/Auth.md) — Регистрация, вход, выход

- [POST /auth/signup — Регистрация](methods/Auth.md#post-authsignup--регистрация)
- [POST /auth/login — Авторизация](methods/Auth.md#post-authlogin--авторизация)
- [POST /auth/logout — Завершение сессии](methods/Auth.md#post-authlogout--завершение-сессии)

## [/profile](methods/Profile.md) — Профиль текущего пользователя

- [GET /profile/get — Профиль текущего пользователя](methods/Profile.md#get-profileget--профиль-текущего-пользователя)
- [POST /profile/setAvatar — Установка аватара](methods/Profile.md#post-profilesetavatar--установка-аватара)
- [POST /profile/removeAvatar — Удаление аватара](methods/Profile.md#post-profileremoveavatar--удаление-аватара)

## [/files](methods/Files.md) — Файлы

- [POST /files/uploadImage — Загрузка изображения](methods/Files.md#post-filesuploadimage--загрузка-изображения)
- [GET /files/get — Информация о файле](methods/Files.md#get-filesget--информация-о-файле)

## [/users](methods/Users.md) — Пользователи

- [GET /users/getList — Список пользователей](methods/Users.md#get-usersgetlist--список-пользователей)
- [GET /users/getBatch — Пользователи по списку id](methods/Users.md#get-usersgetbatch--пользователи-по-списку-id)

## [/conversations](methods/Conversations.md) — Переписки

- [POST /conversations/createSingle — Создание личной переписки](methods/Conversations.md#post-conversationscreatesingle--создание-личной-переписки)
- [GET /conversations/getList — Список переписок](methods/Conversations.md#get-conversationsgetlist--список-переписок)
- [GET /conversations/getBatch — Переписки по списку id](methods/Conversations.md#get-conversationsgetbatch--переписки-по-списку-id)

## [/messages](methods/Messages.md) — Сообщения

- [GET /messages/getList — Сообщения переписки](methods/Messages.md#get-messagesgetlist--сообщения-переписки)
- [GET /messages/getBlock — Сообщения блока](methods/Messages.md#get-messagesgetblock--сообщения-блока)
- [POST /messages/send — Отправка сообщения](methods/Messages.md#post-messagessend--отправка-сообщения)
- [POST /messages/edit — Редактирование сообщения](methods/Messages.md#post-messagesedit--редактирование-сообщения)
