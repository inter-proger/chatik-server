# API Documentation

[← README](../../README.md)

**Base URL:** `http://localhost:8080`

| Документ | Содержание |
|----------|------------|
| [Общие сведения](General.md) | Формат запросов, сессия, формат ответа, actions |
| [Объекты](Objects.md) | User, File, Conversation, LastMessage, Message, блоки сообщений, MessageBlock, WebsocketConnectInfo |
| [Коды ошибок](Errors.md) | Коды `errorCode` и методы, которые их возвращают |
| [Методы API](Methods.md) | Оглавление REST-методов клиентского приложения по группам: [`/global`](methods/Global.md), [`/auth`](methods/Auth.md), [`/profile`](methods/Profile.md), [`/files`](methods/Files.md), [`/users`](methods/Users.md), [`/conversations`](methods/Conversations.md), [`/messages`](methods/Messages.md) |
| [WebSocket](WebSocket.md) | Протокол: подключение, формат сообщений, подтверждения, коды закрытия. События по группам: [`auth`](websocket/Auth.md), [`events`](websocket/Events.md), [`conversations`](websocket/Conversations.md), [`messages`](websocket/Messages.md) |
| [Админ-панель](Admin.md) | Веб-интерфейс `/admin` и его методы (клиентским приложениям не нужны) |

Серверное устройство WebSocket (не часть клиентского контракта) — [architecture/WebSocket.md](../architecture/WebSocket.md).
