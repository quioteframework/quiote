<?php

use PHPUnit\Framework\TestCase;
use Agavi\I18n\DateTimeFacade;

/**
 * Modern equivalent for Ticket958Test: ensure custom offset zone creation works.
 */
class OffsetTimezoneCreationModernTest extends TestCase
{
    public function testCustomOffsetZoneCreation()
    {
        $dt = DateTimeFacade::parse('2024-06-15 10:00:00', 'yyyy-MM-dd HH:mm:ss', '+0530');
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        // +05:30 -> subtract 5h30m
        $this->assertEquals('2024-06-15 04:30:00', $utc->format('Y-m-d H:i:s'));
    }
}
