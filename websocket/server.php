<?php

/**
 * WebSocket-сервер. Запуск: php websocket/server.php
 *
 * Долгоживущий процесс рядом с API: принимает клиентов на WEBSOCKET_LISTEN и вызовы API
 * (publish, closeSession) на 127.0.0.1:WEBSOCKET_INTERNAL_PORT. Останавливается по SIGTERM / SIGINT,
 * закрывая соединения с кодом 1001.
 *
 * Опции (для тестов): --auth-timeout=секунд, --idle-timeout=секунд, --max-connections=N,
 * --max-output-buffer=байт
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../config/local.php';
require_once LOCAL_CONFIG_DIR . '/app.php';
require_once __DIR__ . '/../config/websocket.php';
require_once __DIR__ . '/WsServer.php';

if (!defined('WEBSOCKET_INTERNAL_SECRET')) {
    fwrite(STDERR, "WEBSOCKET_INTERNAL_SECRET is not configured (config/app.php)\n");
    exit(1);
}

$options = getopt('', ['auth-timeout:', 'idle-timeout:', 'max-connections:', 'max-output-buffer:']);

(new WsServer([
    'listen'          => WEBSOCKET_LISTEN,
    'internalPort'    => WEBSOCKET_INTERNAL_PORT,
    'secret'          => WEBSOCKET_INTERNAL_SECRET,
    'authTimeout'     => (float) ($options['auth-timeout'] ?? WEBSOCKET_AUTH_TIMEOUT),
    'idleTimeout'     => (float) ($options['idle-timeout'] ?? WEBSOCKET_IDLE_TIMEOUT),
    'maxMessageSize'  => WEBSOCKET_MAX_MESSAGE_SIZE,
    'ackTimeout'      => WEBSOCKET_ACK_TIMEOUT,
    'maxUnacked'      => WEBSOCKET_MAX_UNACKED,
    'maxConnections'  => (int) ($options['max-connections'] ?? WEBSOCKET_MAX_CONNECTIONS),
    'maxOutputBuffer' => (int) ($options['max-output-buffer'] ?? WEBSOCKET_MAX_OUTPUT_BUFFER),
]))->run();
