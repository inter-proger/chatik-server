<?php

require_once __DIR__ . '/../../websocket/WsMessage.php';

class WsMessageTest extends TestCase
{
    public function testParse(): void
    {
        $message = WsMessage::parse('{"uid":"u1","group":"auth","event":"connect","body":{"userId":1,"token":"t"},"userIds":[5]}');

        $this->assertSame(['uid' => 'u1', 'group' => 'auth', 'event' => 'connect', 'body' => ['userId' => 1, 'token' => 't']], $message);
    }

    public function testParseEmptyBody(): void
    {
        $this->assertSame([], WsMessage::parse('{"uid":"u","group":"g","event":"e","body":{}}')['body']);
    }

    public function testParseInvalid(): void
    {
        foreach ([
            'not json',
            '[]',
            '"string"',
            '{"group":"g","event":"e","body":{}}',
            '{"uid":"","group":"g","event":"e","body":{}}',
            '{"uid":1,"group":"g","event":"e","body":{}}',
            '{"uid":"u","event":"e","body":{}}',
            '{"uid":"u","group":"g","event":null,"body":{}}',
            '{"uid":"u","group":"g","event":"e"}',
            '{"uid":"u","group":"g","event":"e","body":[]}',
            '{"uid":"u","group":"g","event":"e","body":"x"}',
            '{"uid":"u","group":"g","event":"e","body":null}',
            "{\"uid\":\"u\xFF\",\"group\":\"g\",\"event\":\"e\",\"body\":{}}",
        ] as $json) {
            $this->assertNull(WsMessage::parse($json), $json);
        }
    }

    public function testEncode(): void
    {
        $this->assertSame(
            '{"uid":"u","group":"auth","event":"connected","body":{}}',
            WsMessage::encode('u', 'auth', 'connected', [])
        );
        $this->assertSame(
            '{"uid":"u","group":"messages","event":"received","body":{"message":{"text":"Привет/"}},"userIds":[1]}',
            WsMessage::encode('u', 'messages', 'received', ['message' => ['text' => 'Привет/']], [1])
        );
    }

    public function testUuid(): void
    {
        $uid = WsMessage::uuid();

        $this->assertSame(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uid));
        $this->assertFalse($uid === WsMessage::uuid());
    }
}
