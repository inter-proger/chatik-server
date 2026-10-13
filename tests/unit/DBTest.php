<?php

class DBTest extends TestCase
{
    public function testMsRoundTrip(): void
    {
        $this->assertSame(1790846400123, $this->roundTrip(1790846400123));
        $this->assertSame(0, $this->roundTrip(0));
    }

    public function testFromMsKeepsMillisecondsRegardlessOfDivPrecision(): void
    {
        $db = DB::get();
        $db->exec('SET div_precision_increment = 0');
        try {
            $this->assertSame(1790846400123, $this->roundTrip(1790846400123));
        } finally {
            $db->exec('SET div_precision_increment = DEFAULT');
        }
    }

    /** Миллисекунды → DATETIME(3) через fromMs() → миллисекунды через ms() */
    private function roundTrip(int $ms): int
    {
        $stmt = DB::get()->prepare(
            'SELECT ' . DB::ms('t') . ' FROM (SELECT CAST(' . DB::fromMs() . ' AS DATETIME(3)) AS t) x'
        );
        $stmt->execute([$ms]);
        return (int) $stmt->fetchColumn();
    }
}
