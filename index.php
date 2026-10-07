<?php

require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/ErrorCode.php';

// Детали ошибок пишем только в лог, клиенту отдаём INTERNAL_ERROR
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    Response::error(ErrorCode::INTERNAL_ERROR);
});

// Фатальные ошибки (например, нехватка памяти) исключением не ловятся
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        http_response_code(500);
        Response::error(ErrorCode::INTERNAL_ERROR);
    }
});

$uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

$routes = [
    '/global/start' => __DIR__ . '/global/start.php',
    '/global/getWebsocketToken' => __DIR__ . '/global/getWebsocketToken.php',
    '/auth/signup' => __DIR__ . '/auth/signup.php',
    '/auth/login'  => __DIR__ . '/auth/login.php',
    '/auth/logout' => __DIR__ . '/auth/logout.php',
    '/profile/get' => __DIR__ . '/profile/get.php',
    '/profile/setAvatar' => __DIR__ . '/profile/setAvatar.php',
    '/profile/removeAvatar' => __DIR__ . '/profile/removeAvatar.php',
    '/files/uploadImage' => __DIR__ . '/files/uploadImage.php',
    '/files/get'   => __DIR__ . '/files/get.php',
    '/users/getList' => __DIR__ . '/users/getList.php',
    '/users/getBatch' => __DIR__ . '/users/getBatch.php',
    '/conversations/createSingle' => __DIR__ . '/conversations/createSingle.php',
    '/conversations/getList' => __DIR__ . '/conversations/getList.php',
    '/messages/getList' => __DIR__ . '/messages/getList.php',
    '/messages/getBlock' => __DIR__ . '/messages/getBlock.php',
    '/messages/send' => __DIR__ . '/messages/send.php',
    '/messages/edit' => __DIR__ . '/messages/edit.php',
    '/admin' => __DIR__ . '/admin/index.php',
    '/admin/login' => __DIR__ . '/admin/login.php',
    '/admin/logout' => __DIR__ . '/admin/logout.php',
    '/admin/getUsers' => __DIR__ . '/admin/getUsers.php',
    '/admin/getConversations' => __DIR__ . '/admin/getConversations.php',
    '/admin/sendMessage' => __DIR__ . '/admin/sendMessage.php',
];

if (!isset($routes[$uri])) {
    http_response_code(404);
    Response::error(404);
}

require $routes[$uri];
