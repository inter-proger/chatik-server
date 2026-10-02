<?php

// Время жизни токена подключения к WebSocket, секунд
define('WEBSOCKET_TOKEN_TTL', 120);

// Сколько ждать auth / connect после открытия соединения, секунд (иначе закрытие с кодом 4002)
define('WEBSOCKET_AUTH_TIMEOUT', 10);

// Соединение, от которого столько секунд не было ни одного фрейма, считается мёртвым.
// Клиент шлёт ping каждые 25 секунд
define('WEBSOCKET_IDLE_TIMEOUT', 60);

// Максимальный размер сообщения от клиента, байт (больше — закрытие с кодом 1009)
define('WEBSOCKET_MAX_MESSAGE_SIZE', 64 * 1024);

// Через сколько секунд неподтверждённое событие пишется в лог; сколько неподтверждённых uid
// хранится на соединение (старые вытесняются)
define('WEBSOCKET_ACK_TIMEOUT', 30);
define('WEBSOCKET_MAX_UNACKED', 1000);

// Таймауты вызовов API → WS-сервер (publish, closeSession), секунд: подключение и весь запрос.
// Неудача логируется и не роняет REST-запрос
define('WEBSOCKET_INTERNAL_CONNECT_TIMEOUT', 0.05);
define('WEBSOCKET_INTERNAL_TIMEOUT', 0.2);

// Максимум одновременных клиентских соединений WS-сервера; сверх него новые соединения сразу
// закрываются. stream_select() не работает с дескрипторами >= FD_SETSIZE (1024): лимит вместе с
// внутренними соединениями (до 16), слушающими сокетами, stdio и соединением с БД должен оставаться ниже
define('WEBSOCKET_MAX_CONNECTIONS', 1000);

// Максимальный размер неотправленных данных соединения, байт. Клиент, который не читает сокет
// (буфер переполнен), разрывается без close-фрейма — у него 1006
define('WEBSOCKET_MAX_OUTPUT_BUFFER', 1024 * 1024);

// Адреса WS-сервера задаются в config/app.php; здесь — значения по умолчанию для конфигов,
// созданных до появления WebSocket. WEBSOCKET_INTERNAL_SECRET умолчания не имеет: без него
// вызовы API → WS-сервер пропускаются (с записью в лог), а WS-сервер не запускается
defined('WEBSOCKET_URL')           || define('WEBSOCKET_URL', 'ws://localhost:8081');
defined('WEBSOCKET_LISTEN')        || define('WEBSOCKET_LISTEN', 'tcp://0.0.0.0:8081');
defined('WEBSOCKET_INTERNAL_PORT') || define('WEBSOCKET_INTERNAL_PORT', 8082);
