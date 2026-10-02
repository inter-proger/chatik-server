<?php

// Базовый URL, с которого отдаются загруженные файлы (без завершающего слеша)
define('BASE_URL', 'http://localhost:8080');

// Внешний адрес WebSocket-сервера — отдаётся клиенту в WebsocketConnectInfo
define('WEBSOCKET_URL', 'ws://localhost:8081');

// Адрес, на котором WebSocket-сервер (websocket/server.php) принимает клиентов
define('WEBSOCKET_LISTEN', 'tcp://0.0.0.0:8081');

// Порт внутреннего эндпоинта WS-сервера для вызовов из API (слушает только 127.0.0.1)
define('WEBSOCKET_INTERNAL_PORT', 8082);

// Общий секрет для вызовов API → WS-сервер (заголовок X-Internal-Secret)
define('WEBSOCKET_INTERNAL_SECRET', 'change-me');
