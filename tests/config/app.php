<?php

define('BASE_URL', 'http://files.test');

// Порты WS-сервера выбирает раннер (tests/run.php) и передаёт через окружение
define('WEBSOCKET_URL', 'ws://127.0.0.1:' . getenv('SERVERAI_TEST_WS_PORT'));
define('WEBSOCKET_LISTEN', 'tcp://127.0.0.1:' . getenv('SERVERAI_TEST_WS_PORT'));
define('WEBSOCKET_INTERNAL_PORT', (int) getenv('SERVERAI_TEST_WS_INTERNAL_PORT'));
define('WEBSOCKET_INTERNAL_SECRET', 'test-secret');
