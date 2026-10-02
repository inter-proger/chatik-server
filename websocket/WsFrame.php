<?php

/** Нарушение протокола WebSocket; код исключения — код закрытия соединения. */
class WsProtocolError extends Exception
{
}

/** Фреймы WebSocket (RFC 6455): сборка и разбор. */
class WsFrame
{
    const OP_CONTINUATION = 0x0;
    const OP_TEXT         = 0x1;
    const OP_BINARY       = 0x2;
    const OP_CLOSE        = 0x8;
    const OP_PING         = 0x9;
    const OP_PONG         = 0xA;

    /** Ошибка протокола — см. «Коды закрытия соединения» в API_Documentation.md */
    const CLOSE_PROTOCOL_ERROR = 4000;
    const CLOSE_TOO_BIG        = 1009;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** Цельный (FIN) фрейм. Фреймы клиента маскируются ($mask = true), сервера — нет. */
    public static function encode(int $opcode, string $payload = '', bool $mask = false): string
    {
        $length  = strlen($payload);
        $maskBit = $mask ? 0x80 : 0;

        $frame = chr(0x80 | $opcode);
        if ($length < 126) {
            $frame .= chr($maskBit | $length);
        } elseif ($length < 65536) {
            $frame .= chr($maskBit | 126) . pack('n', $length);
        } else {
            $frame .= chr($maskBit | 127) . pack('J', $length);
        }

        if (!$mask) {
            return $frame . $payload;
        }
        $key = random_bytes(4);

        return $frame . $key . self::applyMask($payload, $key);
    }

    /**
     * Извлекает из начала буфера один фрейм: ['fin' => bool, 'opcode' => int, 'payload' => string].
     * null — фрейм пришёл не целиком (буфер не меняется).
     *
     * @throws WsProtocolError немаскированный фрейм клиента ($requireMask), зарезервированные биты
     *                         или опкод, некорректный управляющий фрейм (4000); payload больше $maxPayload (1009)
     */
    public static function decode(string &$buffer, bool $requireMask, int $maxPayload): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }
        $b0 = ord($buffer[0]);
        $b1 = ord($buffer[1]);

        $fin    = (bool) ($b0 & 0x80);
        $opcode = $b0 & 0x0F;
        $masked = (bool) ($b1 & 0x80);

        if ($b0 & 0x70) {
            throw new WsProtocolError('Reserved bits set', self::CLOSE_PROTOCOL_ERROR);
        }
        if (!in_array($opcode, [self::OP_CONTINUATION, self::OP_TEXT, self::OP_BINARY, self::OP_CLOSE, self::OP_PING, self::OP_PONG], true)) {
            throw new WsProtocolError("Unknown opcode $opcode", self::CLOSE_PROTOCOL_ERROR);
        }
        if ($requireMask && !$masked) {
            throw new WsProtocolError('Unmasked client frame', self::CLOSE_PROTOCOL_ERROR);
        }

        $length = $b1 & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $length = unpack('n', $buffer, 2)[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $length = unpack('J', $buffer, 2)[1];
            $offset = 10;
        }

        if ($opcode >= self::OP_CLOSE && ($length > 125 || !$fin)) {
            throw new WsProtocolError('Invalid control frame', self::CLOSE_PROTOCOL_ERROR);
        }
        // Старший бит 64-битной длины даёт отрицательное число
        if ($length < 0 || $length > $maxPayload) {
            throw new WsProtocolError('Frame too big', self::CLOSE_TOO_BIG);
        }

        $keyLength = $masked ? 4 : 0;
        if (strlen($buffer) < $offset + $keyLength + $length) {
            return null;
        }

        $payload = substr($buffer, $offset + $keyLength, $length);
        if ($masked) {
            $payload = self::applyMask($payload, substr($buffer, $offset, 4));
        }
        $buffer = substr($buffer, $offset + $keyLength + $length);

        return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
    }

    /** Значение Sec-WebSocket-Accept для ключа из handshake */
    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    /** Payload close-фрейма с кодом */
    public static function closePayload(int $code): string
    {
        return pack('n', $code);
    }

    /** Код из payload close-фрейма; null — код не передан */
    public static function closeCode(string $payload): ?int
    {
        return strlen($payload) >= 2 ? unpack('n', $payload)[1] : null;
    }

    private static function applyMask(string $payload, string $key): string
    {
        $length = strlen($payload);

        return $payload ^ substr(str_repeat($key, intdiv($length + 3, 4)), 0, $length);
    }
}
