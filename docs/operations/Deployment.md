# Развёртывание сервера

[← README](../../README.md)

Инструкция по установке ServerAi на чистый сервер. Пример — Ubuntu 24.04, nginx + PHP-FPM, MySQL 8, systemd; на других дистрибутивах меняются только имена пакетов и пути. Домен в примерах — `api.example.com`, каталог проекта — `/var/www/serverai`.

Без своего сервера — с локальной машины через туннель: [«Локальный сервер через туннель Tuna»](#локальный-сервер-через-туннель-tuna).

Из чего состоит сервер:

- **REST API** — `index.php` под PHP-FPM, все запросы к API идут через него;
- **статика** — загруженные файлы в `static/uploads/` и документы в `static/docs/`, отдаёт nginx напрямую;
- **WebSocket-сервер** — отдельный долгоживущий процесс `websocket/server.php` (CLI), за nginx; устройство — [architecture/WebSocket.md](../architecture/WebSocket.md);
- **MySQL** — одна БД, схема — миграции из `db/`.

```
клиент ──https──▶ nginx ──fastcgi──▶ PHP-FPM (index.php) ──▶ MySQL
       ──wss────▶ nginx ──http─────▶ websocket/server.php :8081 ──▶ MySQL
                                     ▲ 127.0.0.1:8082 (publish, closeSession из API)
```

## 1. Требования

| Компонент | Версия                    | Зачем                                                                    |
| --------- | ------------------------- | ------------------------------------------------------------------------ |
| PHP       | 8.1+ (FPM и CLI)          | код использует `match`, `str_contains`, типизированные свойства          |
| MySQL     | 8.0+                      | `DATETIME(3)`, колонки `JSON`, `utf8mb4`                                 |
| nginx     | любая актуальная          | TLS, статика, проксирование WebSocket                                    |

Расширения PHP: `pdo_mysql`, `gd` (с поддержкой JPEG, PNG и WebP), `mbstring`, `exif`, `fileinfo`, `pcntl` (только для CLI — нужен WS-серверу для корректной остановки по `SIGTERM`).

## 2. Пакеты

```bash
sudo apt update
sudo apt install nginx mysql-server git \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-gd php8.3-mbstring
```

`exif`, `fileinfo` и `pcntl` в Ubuntu входят в `php8.3-common` / `php8.3-cli`. Проверка:

```bash
php -m | grep -E 'pdo_mysql|gd|mbstring|exif|fileinfo|pcntl'
php -r 'var_dump(gd_info()["WebP Support"]);'
```

Должны вывестись все шесть расширений и `bool(true)`.

## 3. Код

```bash
sudo mkdir -p /var/www/serverai
sudo chown "$USER" /var/www/serverai
git clone <адрес репозитория> /var/www/serverai
```

Каталоги, в которые пишет PHP, — загруженные файлы и сессии (`storage/sessions` создаётся сам при первом запросе):

```bash
cd /var/www/serverai
mkdir -p storage
sudo chown -R www-data:www-data static/uploads storage
```

## 4. База данных

Создать БД и отдельного пользователя (пароль — свой):

```bash
sudo mysql
```

```sql
CREATE DATABASE serverai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'serverai'@'localhost' IDENTIFIED BY 'сложный-пароль';
GRANT ALL PRIVILEGES ON serverai.* TO 'serverai'@'localhost';
```

Применить миграции по порядку номеров:

```bash
cd /var/www/serverai
for f in db/*.sql; do
    echo "$f"
    mysql -u serverai -p'сложный-пароль' serverai < "$f" || break
done
```

Учёта применённых миграций в проекте нет: при обновлении применяются только новые файлы (см. [раздел 11](#11-обновление)). Номер последней применённой миграции стоит записать — например, в файл рядом с конфигом.

Часовой пояс MySQL менять не нужно: приложение выставляет `time_zone = '+00:00'` на каждом соединении, время в БД хранится в UTC.

## 5. Конфигурация

Локальные конфиги не хранятся в репозитории — создаются из шаблонов:

```bash
cd /var/www/serverai
cp config/db.example.php config/db.php
cp config/app.example.php config/app.php
```

`config/db.php` — доступ к БД из шага 4:

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'serverai');
define('DB_USER', 'serverai');
define('DB_PASS', 'сложный-пароль');
define('DB_CHARSET', 'utf8mb4');
```

`config/app.php`:

```php
// Внешний адрес API; от него строятся url загруженных файлов.
// С https:// cookie сессии становится Secure
define('BASE_URL', 'https://api.example.com');

// Внешний адрес WebSocket — отдаётся клиенту (через nginx, см. шаг 7)
define('WEBSOCKET_URL', 'wss://api.example.com/ws');

// За nginx WS-сервер слушает только локальный интерфейс
define('WEBSOCKET_LISTEN', 'tcp://127.0.0.1:8081');

define('WEBSOCKET_INTERNAL_PORT', 8082);

// Обязательно сменить: без секрета WS-сервер не запустится
define('WEBSOCKET_INTERNAL_SECRET', '...');
```

Секрет — случайная строка:

```bash
php -r 'echo bin2hex(random_bytes(32)), "\n";'
```

Права на конфиги — читать может только владелец и группа `www-data`:

```bash
sudo chown "$USER":www-data config/db.php config/app.php
chmod 640 config/db.php config/app.php
```

Остальные настройки (лимиты загрузки, время жизни сессии, таймауты WebSocket) лежат в `config/files.php`, `config/session.php`, `config/websocket.php` и закоммичены; менять их на сервере обычно не нужно.

## 6. PHP-FPM

Лимиты загрузки должны быть не меньше `UPLOAD_MAX_SIZE` (10 МБ, `config/files.php`). Файл `/etc/php/8.3/fpm/conf.d/99-serverai.ini`:

```ini
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 256M
```

`memory_limit` должен вмещать `IMAGE_MEMORY_LIMIT` (256M) — на время обработки изображения приложение поднимает его само, но значение в php.ini не должно быть жёстко ограничено.

```bash
sudo systemctl restart php8.3-fpm
```

## 7. nginx

Принцип: nginx напрямую отдаёт только `/static/`, `/ws` проксирует в WS-сервер, а **все остальные запросы передаёт в `index.php`**. Так из корня проекта наружу не попадают ни `config/`, ни `db/`, ни `.git`, ни markdown-файлы — на любой неизвестный путь роутер ответит JSON-ошибкой 404.

`/etc/nginx/sites-available/serverai`:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 80;
    server_name api.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name api.example.com;

    # Пути выставит certbot (шаг 8)
    ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;

    root /var/www/serverai;

    # Чуть больше UPLOAD_MAX_SIZE — на заголовки multipart
    client_max_body_size 12m;

    # Загруженные файлы и документы — статикой, PHP здесь не исполняется
    location /static/ {
        try_files $uri =404;
        expires 30d;
        add_header X-Content-Type-Options nosniff;
    }

    # WebSocket. proxy_read_timeout больше интервала клиентских ping (25 с)
    location = /ws {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_read_timeout 75s;
        proxy_send_timeout 75s;
    }

    # Всё остальное — в роутер
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Порт `8082` (внутренний эндпоинт WS-сервера) WS-сервер сам слушает только на `127.0.0.1` — в nginx его не публиковать.

```bash
sudo ln -s /etc/nginx/sites-available/serverai /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

## 8. TLS

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d api.example.com
```

Если сертификата ещё нет, `nginx -t` на шаге 7 не пройдёт из-за путей `ssl_certificate`: сначала выпустить сертификат с временным конфигом на 80-м порту (`certbot --nginx` сделает это сам), потом подложить полный конфиг.

## 9. WebSocket-сервер под systemd

`/etc/systemd/system/serverai-ws.service`:

```ini
[Unit]
Description=ServerAi WebSocket server
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/serverai
ExecStart=/usr/bin/php websocket/server.php
# SIGTERM — штатная остановка: соединения закрываются с 1001, клиенты переподключаются
KillSignal=SIGTERM
Restart=always
RestartSec=2
# Запас над WEBSOCKET_MAX_CONNECTIONS (1000)
LimitNOFILE=4096

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now serverai-ws
sudo systemctl status serverai-ws
```

Лог WS-сервера пишется в stderr, смотреть — `journalctl -u serverai-ws -f`.

Пока WS-сервер не запущен, REST работает: вызовы publish из API только пишут ошибку в лог PHP-FPM, события клиентам не доставляются.

## 10. Обслуживание

**Очистка сессий.** Сессии лежат в `storage/sessions`. В Ubuntu/Debian у PHP отключён встроенный сборщик мусора (`session.gc_probability = 0`) — системный cron чистит только `/var/lib/php/sessions`, поэтому файлы сессий проекта нужно удалять самим. `/etc/cron.d/serverai-sessions`:

```
# Сессии старше SESSION_LIFETIME (30 дней)
17 4 * * * www-data find /var/www/serverai/storage/sessions -type f -name 'sess_*' -mmin +43200 -delete
```

Просроченные токены WebSocket (`websocket_tokens`) чистятся приложением само при выдаче нового токена.

**Логи.** Ошибки API — в логе PHP-FPM (`/var/log/php8.3-fpm.log`) и nginx (`/var/log/nginx/error.log`); клиенту отдаётся только `INTERNAL_ERROR`. WS-сервер — `journalctl -u serverai-ws`.

**Бэкап.** Две вещи: дамп БД и каталог `static/uploads/` — записи `files` ссылаются на файлы в нём.

```bash
mysqldump --single-transaction -u serverai -p serverai | gzip > serverai-$(date +%F).sql.gz
tar czf uploads-$(date +%F).tar.gz -C /var/www/serverai static/uploads
```

## 11. Обновление

```bash
cd /var/www/serverai
git pull
# применить только миграции новее последней применённой, по порядку:
mysql -u serverai -p serverai < db/014_....sql
sudo systemctl reload php8.3-fpm      # сбросить opcache
sudo systemctl restart serverai-ws    # подхватить новый код WS-сервера
```

Перезапуск WS-сервера безопасен: клиенты получат `1001` и переподключатся через `/global/getWebsocketToken`. Если обновление добавило новые константы в `config/*.example.php`, перенести их в локальные `config/db.php` / `config/app.php`.

## 12. Проверка

```bash
# API отвечает JSON
curl -s https://api.example.com/global/start

# неизвестный путь и закрытые файлы — JSON 404, а не содержимое
curl -s https://api.example.com/config/db.php
curl -s https://api.example.com/docs/development/TestUsers.md

# WS-сервер слушает
ss -ltnp | grep -E '8081|8082'
```

Сквозная проверка: зарегистрироваться через `/auth/signup`, войти, загрузить картинку `/files/uploadImage` (проверяет права на `static/uploads` и GD), открыть полученный `url` в браузере, получить токен `/global/getWebsocketToken` и подключиться к `wss://api.example.com/ws` (например, `websocat`) — методы и события описаны в [docs/api/README.md](../api/README.md).

## Безопасность — перед открытием наружу

- **Пароль админ-панели.** Логин и пароль `/admin` сейчас захардкожены в `core/Admin.php` (`admin` / `admin`). До выноса в `config/app.php` — сменить их на сервере или закрыть `/admin` в nginx по IP:

  ```nginx
  location ^~ /admin {
      allow 203.0.113.10;   # свой адрес
      deny all;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root/index.php;
      fastcgi_param SCRIPT_NAME /index.php;
      fastcgi_pass unix:/run/php/php8.3-fpm.sock;
  }
  ```

- **Тестовые пользователи** из [TestUsers.md](../development/TestUsers.md) существуют только в локальной БД — на сервере их не создавать.
- **`WEBSOCKET_INTERNAL_SECRET`** — уникальный для каждого окружения, не `change-me`.
- **Тесты** (`php tests/run.php`) на боевом сервере не запускать: раннер пересоздаёт БД из `tests/config/db.php` и поднимает собственные серверы.
- В файрволе открыть только 22, 80, 443; MySQL, 8081 и 8082 наружу не публикуются.

## Локальный сервер через туннель Tuna

Вариант без VPS и домена: сервер работает на своей машине (пример — macOS с Homebrew), наружу его выводит [Tuna](https://tuna.am/). Бесплатный тариф даёт один постоянный поддомен в `a.tuna.free` (выдаётся автоматически, Tuna показывает его при запуске), HTTPS и WebSocket, но **только один активный туннель** — поэтому REST, статика и `/ws` сводятся локальным nginx на один порт, как в [шаге 7](#7-nginx). TLS делает Tuna, certbot не нужен. Сервер доступен, пока машина включена и не спит.

```
клиент ──https/wss──▶ Tuna ──▶ nginx 127.0.0.1:8090 ─┬─ /ws ──────▶ websocket/server.php :8081
                                                     ├─ /static/ ─▶ файлы
                                                     └─ остальное ▶ PHP-FPM 127.0.0.1:9000 (index.php)
```

**Пакеты и сервисы:**

```bash
brew install php mysql nginx yuccastream/tap/tuna
brew services start mysql
brew services start php
brew services start nginx
```

БД и конфиги — как в шагах [4](#4-база-данных) и [5](#5-конфигурация). PHP-FPM из Homebrew слушает `127.0.0.1:9000`, лимиты загрузки — как в [шаге 6](#6-php-fpm) (`$(brew --prefix)/etc/php/<версия>/conf.d/99-serverai.ini`, затем `brew services restart php`).

**nginx.** Отдельный server-блок на порту только для туннеля, `$(brew --prefix)/etc/nginx/servers/serverai-tunnel.conf` (`root` — свой каталог проекта):

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    # Только локально — снаружи доступ лишь через туннель
    listen 127.0.0.1:8090;
    server_name _;

    root /Users/<user>/Projects/ServerAi;

    client_max_body_size 12m;

    location /static/ {
        try_files $uri =404;
        expires 30d;
        add_header X-Content-Type-Options nosniff;
    }

    location = /ws {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_read_timeout 75s;
        proxy_send_timeout 75s;
    }

    # Админ-панель (пароль захардкожен, см. «Безопасность») наружу не публикуется
    location ^~ /admin {
        default_type application/json;
        return 404 '{"status":0,"errorCode":404}';
    }

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass 127.0.0.1:9000;
    }
}
```

```bash
nginx -t && nginx -s reload
```

Публиковать в туннель только этот порт: dev-конфиг с `try_files $uri` и `root` в корне проекта отдаёт наружу `config/`, `db/`, `.git` и markdown-файлы.

**Туннель.** Войти в Tuna (токен — в личном кабинете) и запустить туннель на постоянном поддомене — без `--subdomain` выдаётся случайный адрес, а постоянный Tuna показывает при запуске:

```bash
tuna config save-token <токен>
tuna http 8090 --subdomain=<поддомен>
```

**`config/app.php`** — внешние адреса через туннель, WS-сервер — за nginx:

```php
define('BASE_URL', 'https://<поддомен>.a.tuna.free');
define('WEBSOCKET_URL', 'wss://<поддомен>.a.tuna.free/ws');
define('WEBSOCKET_LISTEN', 'tcp://127.0.0.1:8081');
```

`BASE_URL` один на сервер: url загруженных файлов строятся от него, поэтому и клиенты в локальной сети получат адреса через туннель.

**WebSocket-сервер** — в отдельном терминале, остановка — `Ctrl+C`:

```bash
php websocket/server.php
```

**Проверка** — как в [шаге 12](#12-проверка), с адресом туннеля:

```bash
curl -s https://<поддомен>.a.tuna.free/global/start
curl -s https://<поддомен>.a.tuna.free/config/db.php
```

Перед тем как раздавать адрес: в локальной БД есть тестовые пользователи с паролями из [TestUsers.md](../development/TestUsers.md) — через туннель под ними сможет войти любой. Для доступа только своим — `tuna http 8090 --basic-auth=логин:пароль` (клиенту тогда нужен заголовок `Authorization`).
