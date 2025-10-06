<?php

use PHPUnit\Framework\TestCase;
use Agavi\I18n\DateTimeFacade;

/**
 * Slim parity for essential behavior of AgaviGregorianCalendarTest using native DateTime.
 */
class GregorianCalendarModernTest extends TestCase
{
    public function testNativeDateTimeFields()
    {
        $dt = DateTimeFacade::parse('2009-02-21 12:30:20', 'yyyy-MM-dd HH:mm:ss', 'Europe/Berlin');
        $this->assertEquals('2009-02-21 12:30:20 Europe/Berlin', $dt->format('Y-m-d H:i:s e'));
    }

    public function testCustomOffsetTimezone()
    {
        $dt = DateTimeFacade::parse('2009-02-21 12:30:20', 'yyyy-MM-dd HH:mm:ss', '+0100');
        $this->assertEquals('2009-02-21 11:30:20', $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }
}
