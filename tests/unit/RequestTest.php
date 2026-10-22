<?php

require_once __DIR__ . '/../../core/Request.php';

class RequestTest extends TestCase
{
    public function testParseIdListFromString(): void
    {
        $this->assertSame([3, 1, 2], Request::parseIdList('3, 1,2,3', 10));
    }

    public function testParseIdListFromArray(): void
    {
        $this->assertSame([5, 0], Request::parseIdList(['5', ' 0 ', '5'], 10));
    }

    public function testParseIdListLimitCountsDuplicates(): void
    {
        $this->assertSame([1, 2], Request::parseIdList('1,2', 2));
        $this->assertNull(Request::parseIdList('1,1,1', 2));
    }

    public function testParseIdListInvalid(): void
    {
        foreach (['', [], '1,', '1,,2', '-1', '1.5', 'abc', ['1', ['2']], [1], 7] as $value) {
            $this->assertNull(Request::parseIdList($value, 10), 'Expected null for ' . json_encode($value));
        }
    }

    public function testNormalizeText(): void
    {
        $this->assertSame('Hi 🚀', Request::normalizeText("\u{00A0} Hi 🚀\u{200B}\n", 10));
        $this->assertSame('', Request::normalizeText("  \u{FEFF}", 10));
        $this->assertSame('a?b', Request::normalizeText("a\xFFb", 10));
        $this->assertSame(str_repeat('я', 3), Request::normalizeText(str_repeat('я', 3), 3));
        $this->assertNull(Request::normalizeText(str_repeat('я', 4), 3));
        $this->assertNull(Request::normalizeText(['a'], 10));
        $this->assertNull(Request::normalizeText(null, 10));
    }

    public function testParseUint(): void
    {
        $this->assertSame(0, Request::parseUint('0'));
        $this->assertSame(1790846400123, Request::parseUint('1790846400123'));
        $this->assertSame(7, Request::parseUint('007'));
        $this->assertSame(0, Request::parseUint('000'));
        $this->assertSame(PHP_INT_MAX, Request::parseUint('0' . PHP_INT_MAX));
        foreach (['', '-1', '1.5', ' 1', 'abc', '99999999999999999999', ['1'], 1, null] as $value) {
            $this->assertNull(Request::parseUint($value), 'Expected null for ' . json_encode($value));
        }
    }
}
