<?php

use PHPUnit\Framework\TestCase;
use Quiote\I18n\DateTimeFacade;

/**
 * Modern replacement coverage for a subset of legacy DateFormat tests.
 * Focuses on parse/format + timezone handling using DateTimeFacade (Intl/nativ e APIs).
 */
class DateFormatModernTest extends TestCase
{
    public function testParseWithExplicitTimezone(): void
    {
        $dt = DateTimeFacade::parse('2008-11-19 23:00:00', 'yyyy-MM-dd HH:mm:ss', 'Europe/Berlin');
        // Berlin offset in late 2008-11 is +01:00 (standard time)
        $this->assertEquals('2008-11-19 22:00:00', DateTimeFacade::format($dt->setTimezone(new DateTimeZone('UTC')), 'yyyy-MM-dd HH:mm:ss'));
        $this->assertEquals('2008-11-19 23:00:00', DateTimeFacade::format($dt, 'yyyy-MM-dd HH:mm:ss'));
        $this->assertEquals('Europe/Berlin', $dt->getTimezone()->getName());
    }

    public function testRoundTripFormatting(): void
    {
        $pattern = 'yyyy-MM-dd HH:mm:ss';
        $original = '2031-03-15 05:06:07';
        $dt = DateTimeFacade::parse($original, $pattern, 'UTC');
        $this->assertSame($original, DateTimeFacade::format($dt, $pattern));
    }

    public function testTimezoneConversion(): void
    {
        $utc = DateTimeFacade::parse('2025-10-01 12:00:00', 'yyyy-MM-dd HH:mm:ss', 'UTC');
        $ny = $utc->setTimezone(new DateTimeZone('America/New_York'));
        $this->assertEquals('2025-10-01 08:00:00', DateTimeFacade::format($ny, 'yyyy-MM-dd HH:mm:ss')); // EDT (UTC-4) expected
    }
}
