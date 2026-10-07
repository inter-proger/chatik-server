<?php

require_once __DIR__ . '/../core/Admin.php';
require_once __DIR__ . '/../core/Response.php';

Admin::require();

$_SESSION = [];

$params = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires'  => time() - 42000,
    'path'     => $params['path'],
    'domain'   => $params['domain'],
    'secure'   => $params['secure'],
    'httponly' => $params['httponly'],
    'samesite' => $params['samesite'],
]);

session_destroy();

Response::success();
