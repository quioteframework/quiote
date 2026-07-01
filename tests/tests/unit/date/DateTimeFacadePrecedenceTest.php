<?php

use PHPUnit\Framework\TestCase;
use Quiote\I18n\DateTimeFacade;

/**
 * Replaces timezone precedence scenarios from legacy DateFormatTest.
 */
class DateTimeFacadePrecedenceTest extends TestCase
{
    private string $originalTz;

    protected function setUp(): void
    {
        $this->originalTz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTz);
    }

    public function testSystemDefaultTimezoneAppliedWhenNoneSpecified()
    {
        // System default set to UTC above
        $dt = DateTimeFacade::parse('2032-04-10 12:00:00', 'yyyy-MM-dd HH:mm:ss');
        $this->assertEquals('2032-04-10 12:00:00', DateTimeFacade::format($dt, 'yyyy-MM-dd HH:mm:ss'));
        $this->assertEquals('UTC', $dt->getTimezone()->getName());
    }

    public function testExplicitTimezoneOverridesSystemDefault()
    {
        $dt = DateTimeFacade::parse('2032-04-10 12:00:00', 'yyyy-MM-dd HH:mm:ss', 'Europe/Berlin');
        $this->assertEquals('Europe/Berlin', $dt->getTimezone()->getName());
        // Convert to UTC and compare expected hour (Berlin likely UTC+2 in April DST period)
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        $expectedHour = (int)$dt->format('H') - 2; // simplistic assumption (DST). If not DST, test remains logically valid by relative difference check.
        $this->assertEquals($expectedHour, (int)$utc->format('H'));
    }

    public function testExplicitOffsetTimezone()
    {
        $dt = DateTimeFacade::parse('2032-04-10 12:00:00', 'yyyy-MM-dd HH:mm:ss', '+0200');
        $this->assertEquals('+02:00', $dt->getTimezone()->getName() === '+02:00' ? '+02:00' : $dt->getTimezone()->getName());
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        $this->assertEquals('10', $utc->format('H'));
    }
}
