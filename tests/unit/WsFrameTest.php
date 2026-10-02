<?php

require_once __DIR__ . '/../../websocket/WsFrame.php';

class WsFrameTest extends TestCase
{
    public function testRoundTripAllLengthEncodings(): void
    {
        foreach ([0, 1, 125, 126, 65535, 65536] as $length) {
            foreach ([false, true] as $mask) {
                $payload = str_repeat("\xA5", $length);
                $buffer  = WsFrame::encode(WsFrame::OP_TEXT, $payload, $mask) . 'tail';

                $frame = WsFrame::decode($buffer, $mask, 1 << 20);

                $this->assertSame(['fin' => true, 'opcode' => WsFrame::OP_TEXT, 'payload' => $payload], $frame, "length $length");
                $this->assertSame('tail', $buffer, 'Rest of the buffer is kept');
            }
        }
    }

    public function testIncompleteFrameLeavesBuffer(): void
    {
        $frame = WsFrame::encode(WsFrame::OP_TEXT, str_repeat('a', 300), true);

        foreach ([0, 1, 3, 7, strlen($frame) - 1] as $cut) {
            $buffer = substr($frame, 0, $cut);
            $this->assertNull(WsFrame::decode($buffer, true, 1000));
            $this->assertSame(substr($frame, 0, $cut), $buffer);
        }
    }

    public function testMaskedPayloadDiffersOnWire(): void
    {
        $frame = WsFrame::encode(WsFrame::OP_TEXT, 'hello hello', true);

        $this->assertFalse(str_contains($frame, 'hello hello'));
        $this->assertSame(0x80, ord($frame[1]) & 0x80);
    }

    public function testUnmaskedClientFrame(): void
    {
        $this->assertProtocolError(4000, WsFrame::encode(WsFrame::OP_TEXT, 'x'), true);
    }

    public function testTooBig(): void
    {
        $this->assertProtocolError(1009, WsFrame::encode(WsFrame::OP_TEXT, str_repeat('a', 101), true), true, 100);
        // Длина проверяется по заголовку, не дожидаясь всего payload
        $this->assertProtocolError(1009, substr(WsFrame::encode(WsFrame::OP_TEXT, str_repeat('a', 70000), true), 0, 20), true, 100);
        // 64-битная длина со старшим битом
        $this->assertProtocolError(1009, "\x81\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF", false, 100);
    }

    public function testInvalidFrames(): void
    {
        $this->assertProtocolError(4000, "\xC1\x80abcd", true);     // RSV1
        $this->assertProtocolError(4000, "\x83\x80abcd", true);     // неизвестный опкод
        $this->assertProtocolError(4000, "\x09\x80abcd", true);     // фрагментированный ping
        $this->assertProtocolError(4000, WsFrame::encode(WsFrame::OP_PING, str_repeat('a', 126), true), true);
    }

    public function testNotFinalFrame(): void
    {
        $buffer = "\x01\x03abc";

        $this->assertSame(['fin' => false, 'opcode' => WsFrame::OP_TEXT, 'payload' => 'abc'], WsFrame::decode($buffer, false, 100));
    }

    public function testAcceptKey(): void
    {
        // Пример из RFC 6455
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', WsFrame::acceptKey('dGhlIHNhbXBsZSBub25jZQ=='));
    }

    public function testCloseCode(): void
    {
        $this->assertSame(4003, WsFrame::closeCode(WsFrame::closePayload(4003)));
        $this->assertSame(1000, WsFrame::closeCode(WsFrame::closePayload(1000) . 'reason'));
        $this->assertNull(WsFrame::closeCode(''));
    }

    private function assertProtocolError(int $code, string $buffer, bool $requireMask, int $maxPayload = 1000): void
    {
        try {
            WsFrame::decode($buffer, $requireMask, $maxPayload);
        } catch (WsProtocolError $e) {
            $this->assertSame($code, $e->getCode());
            return;
        }
        $this->fail("Expected protocol error $code");
    }
}
