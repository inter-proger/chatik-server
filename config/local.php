<?php

// Каталог с локальными настройками (db.php, app.php). Тесты подставляют свой через SERVERAI_CONFIG_DIR
define('LOCAL_CONFIG_DIR', getenv('SERVERAI_CONFIG_DIR') ?: __DIR__);
