<?php

// Отдельная БД для тестов: пересоздаётся при каждом запуске tests/run.php.
// Имя обязано оканчиваться на _test — раннер откажется работать с другой базой
define('DB_HOST', getenv('TEST_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('TEST_DB_PORT') ?: 3306));
define('DB_NAME', getenv('TEST_DB_NAME') ?: 'serverai_test');
define('DB_USER', getenv('TEST_DB_USER') ?: 'root');
define('DB_PASS', getenv('TEST_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');
