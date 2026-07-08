<?php

use PHPUnit\Framework\TestCase;
use Quiote\I18n\DateTimeFacade;

class DateTimeFacadeTest extends TestCase
{
    public function testParseAndFormatBasicPattern(): void
    {
        $dt = DateTimeFacade::parse('2008-11-19 23:00:00', 'yyyy-MM-dd HH:mm:ss', 'Europe/Berlin');
        $this->assertEquals('2008-11-19 23:00:00', DateTimeFacade::format($dt, 'yyyy-MM-dd HH:mm:ss'));
        $this->assertEquals('Europe/Berlin', $dt->getTimezone()->getName());
    }

    public function testParseUTCAndFormatDifferentTimezone(): void
    {
        $dt = DateTimeFacade::parse('2025-10-01 12:30:15', 'yyyy-MM-dd HH:mm:ss', 'UTC');
        $berlin = $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        $this->assertEquals('2025-10-01 12:30:15', DateTimeFacade::format($dt, 'yyyy-MM-dd HH:mm:ss'));
        $this->assertEquals('2025-10-01 14:30:15', DateTimeFacade::format($berlin, 'yyyy-MM-dd HH:mm:ss'));
    }

    public function testRoundTripFallbackPattern(): void
    {
        $pattern = 'yyyy-MM-dd HH:mm:ss';
        $original = '2030-02-05 07:08:09';
        $dt = DateTimeFacade::parse($original, $pattern, 'UTC');
        $this->assertSame($original, DateTimeFacade::format($dt, $pattern));
    }

    public function testUnsupportedTokenThrows(): void
    {
        $this->expectException(RuntimeException::class);
        DateTimeFacade::format(new DateTimeImmutable('now', new DateTimeZone('UTC')), 'yyyy-MM-dd XXX');
    }
}
