<?php

require_once __DIR__ . '/core/Response.php';

$uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

$routes = [
    '/auth/signup' => __DIR__ . '/auth/signup.php',
    '/auth/login'  => __DIR__ . '/auth/login.php',
    '/auth/logout' => __DIR__ . '/auth/logout.php',
    '/profile/get' => __DIR__ . '/profile/get.php',
    '/profile/setAvatar' => __DIR__ . '/profile/setAvatar.php',
    '/profile/removeAvatar' => __DIR__ . '/profile/removeAvatar.php',
    '/files/uploadImage' => __DIR__ . '/files/uploadImage.php',
    '/files/get'   => __DIR__ . '/files/get.php',
    '/users/getList' => __DIR__ . '/users/getList.php',
    '/conversations/createSingle' => __DIR__ . '/conversations/createSingle.php',
    '/conversations/getList' => __DIR__ . '/conversations/getList.php',
];

if (!isset($routes[$uri])) {
    http_response_code(404);
    Response::error(404);
}

require $routes[$uri];
