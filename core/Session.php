<?php

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/app.php';

class Session
{
    /**
     * Стартует сессию со временем жизни SESSION_LIFETIME и продлевает её:
     * cookie переотправляется с новым сроком, а файл сессии обновляет mtime
     * (session.lazy_write вызывает touch даже без изменения данных).
     */
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $dir = dirname(__DIR__) . '/' . SESSION_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        ini_set('session.save_path', $dir);
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            // По HTTPS cookie только защищённая; локально (BASE_URL на http) работает как раньше
            'secure'   => str_starts_with(BASE_URL, 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        // Для уже существующей сессии PHP cookie не переотправляет — продлеваем срок сами
        if (($_COOKIE[session_name()] ?? null) === session_id()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires'  => time() + SESSION_LIFETIME,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }
    }
}
