<?php

class Response
{
    public static function success(array $data = []): void
    {
        // Пустой массив сериализуется в [], а не в {} — приводим к объекту
        self::send(['status' => 1, 'response' => (object) $data]);
    }

    public static function error(int $code): void
    {
        self::send(['status' => 0, 'errorCode' => $code]);
    }

    private static function send(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        // Невалидный UTF-8 заменяется на U+FFFD, иначе json_encode вернёт false и тело будет пустым
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
