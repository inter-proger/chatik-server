<?php

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/ErrorCode.php';

/**
 * Разбор параметров запроса, общий для нескольких методов.
 */
class Request
{
    /**
     * Список id из параметра $name (методы getBatch). Принимает и ids=1,2,3, и ids[]=1&ids[]=2.
     * Невалидный список — ошибка INVALID_PARAMS (см. parseIdList).
     */
    public static function requireIdList(string $name, int $maxCount): array
    {
        $ids = self::parseIdList($_REQUEST[$name] ?? '', $maxCount);
        if ($ids === null) {
            Response::error(ErrorCode::INVALID_PARAMS);
        }

        return $ids;
    }

    /**
     * Необязательный параметр $name — целое неотрицательное число. null — если параметр не передан
     * или пуст; невалидное значение — ошибка INVALID_PARAMS (см. parseUint).
     */
    public static function optionalUint(string $name): ?int
    {
        $value = $_REQUEST[$name] ?? '';
        if ($value === '') {
            return null;
        }

        $value = self::parseUint($value);
        if ($value === null) {
            Response::error(ErrorCode::INVALID_PARAMS);
        }

        return $value;
    }

    /**
     * Целое неотрицательное число из строки (только цифры, не больше PHP_INT_MAX) или null.
     * Ведущие нули допускаются — как у остальных числовых параметров.
     */
    public static function parseUint(mixed $value): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        // FILTER_VALIDATE_INT не принимает ведущие нули
        $int = filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT);
        return $int === false ? null : $int;
    }

    /**
     * Разбирает список id: строку через запятую или массив строк; пробелы вокруг элементов допускаются.
     * Возвращает целые неотрицательные id без повторов в порядке первого появления
     * или null, если список пуст, длиннее $maxCount (до удаления повторов)
     * или содержит элемент, не являющийся целым неотрицательным числом.
     */
    public static function parseIdList(mixed $value, int $maxCount): ?array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        if (!is_array($value) || !$value || count($value) > $maxCount) {
            return null;
        }

        $ids = [];
        foreach ($value as $id) {
            $id = is_string($id) ? trim($id) : $id;
            if (!is_string($id) || !ctype_digit($id)) {
                return null;
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }
}
