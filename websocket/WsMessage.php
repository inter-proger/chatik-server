<?php

/** Сообщения протокола: JSON с полями uid, group, event, body (+ userIds в событиях сервера). */
class WsMessage
{
    /**
     * Разбирает сообщение клиента: ['uid', 'group', 'event', 'body' => array].
     * null — невалидный конверт: не JSON-объект, нет uid/group/event/body, неверные типы.
     * userIds в сообщениях клиента игнорируется.
     */
    public static function parse(string $json): ?array
    {
        $object = json_decode($json, false, 64);
        if (!$object instanceof stdClass) {
            return null;
        }
        foreach (['uid', 'group', 'event'] as $field) {
            if (!isset($object->$field) || !is_string($object->$field) || $object->$field === '') {
                return null;
            }
        }
        if (!isset($object->body) || !$object->body instanceof stdClass) {
            return null;
        }

        return [
            'uid'   => $object->uid,
            'group' => $object->group,
            'event' => $object->event,
            'body'  => json_decode($json, true, 64)['body'],
        ];
    }

    /**
     * JSON события сервера с новым uid. $body — массив или объект (пустой сериализуется в {}),
     * userIds попадает в сообщение, только если не пуст.
     */
    public static function encode(string $uid, string $group, string $event, array|stdClass $body, array $userIds = []): string
    {
        $message = ['uid' => $uid, 'group' => $group, 'event' => $event, 'body' => is_array($body) ? (object) $body : $body];
        if ($userIds) {
            $message['userIds'] = $userIds;
        }

        return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** UUID v4 */
    public static function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
