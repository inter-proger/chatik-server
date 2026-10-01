<?php

/**
 * Actions — дополнительные указания клиенту, которые отдаются в поле actions успешного ответа.
 * Формат каждого: { type, data }.
 */
class Action
{
    const TYPE_USERS = 'users';

    /**
     * Пользователи, фигурирующие в ответе: клиент проверяет их наличие в своём кэше
     * и при необходимости дозапрашивает через /users/getBatch.
     * null — если список пуст (такой action не отдаётся).
     */
    public static function users(array $userIds): ?array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) {
            return null;
        }
        sort($userIds);

        return ['type' => self::TYPE_USERS, 'data' => ['userIds' => $userIds]];
    }
}
