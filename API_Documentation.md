# API Documentation

**Base URL:** `http://localhost:8080`

| Документ | Содержание |
|----------|------------|
| [Общие сведения](docs/api/General.md) | Формат запросов, сессия, формат ответа, actions |
| [Объекты](docs/api/Objects.md) | User, File, Conversation, LastMessage, Message, блоки сообщений, MessageBlock, WebsocketConnectInfo |
| [Коды ошибок](docs/api/Errors.md) | Коды `errorCode` и методы, которые их возвращают |
| [Методы API](docs/api/Methods.md) | Оглавление REST-методов клиентского приложения по группам: [`/global`](docs/api/methods/Global.md), [`/auth`](docs/api/methods/Auth.md), [`/profile`](docs/api/methods/Profile.md), [`/files`](docs/api/methods/Files.md), [`/users`](docs/api/methods/Users.md), [`/conversations`](docs/api/methods/Conversations.md), [`/messages`](docs/api/methods/Messages.md) |
| [WebSocket](docs/api/WebSocket.md) | Протокол: подключение, формат сообщений, подтверждения, коды закрытия. События по группам: [`auth`](docs/api/websocket/Auth.md), [`events`](docs/api/websocket/Events.md), [`conversations`](docs/api/websocket/Conversations.md), [`messages`](docs/api/websocket/Messages.md) |
| [Админ-панель](docs/api/Admin.md) | Веб-интерфейс `/admin` и его методы (клиентским приложениям не нужны) |

Серверное устройство WebSocket (не часть клиентского контракта) — [WebSocket_Architecture.md](WebSocket_Architecture.md).
