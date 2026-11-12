<?php

namespace Tests\Unit;

use App\Services\EnergyLog;
use PHPUnit\Framework\TestCase;

class EnergyLogTest extends TestCase
{
    public function test_parser_validates_reasons_signs_dates_and_occurrences(): void
    {
        $parser = new EnergyLog;
        $text = "Date\tPlayer\tReason\tAmount\r\n"
            ."07/11/2026 14:05:00\tMember\tDeposit\t+300\r\n"
            ."07/11/2026 14:05:00\tMember\tDeposit\t300\r\n"
            ."2026-11-07T14:06:00Z\tMember\tWithdrawal\t-50\r\n"
            ."31/02/2026 14:05:00\tMember\tDeposit\t300\r\n"
            ."2026-11-07T14:07:00Z\tMember\tUnknown\t10\r\n"
            ."2026-11-07T14:07:00Z\tMember\tDeposit\t-10";
        $result = $parser->parse($text, 'dmy', '2026-11-01T00:00:00Z');
        $this->assertCount(3, $result['rows']);
        $this->assertCount(3, $result['errors']);
        $this->assertSame(50, $result['rows'][2]['amount']);
        $this->assertNotSame($result['rows'][0]['fingerprint'], $result['rows'][1]['fingerprint']);
        $this->assertSame('2026-07-11T14:05:00Z', $parser->timestamp('07/11/2026 14:05:00', 'mdy'));
        $cutoff = $parser->parse("2026-11-07T14:05:00.0000001Z\tMember\tDeposit\t300\n"
            ."2026-11-07T14:05:00Z\tMember\tDeposit\t300", 'dmy', '2026-11-07T14:05:00Z');
        $this->assertCount(1, $cutoff['rows']);
        $this->assertSame(1, $cutoff['beforeCutoff']);
    }
}
