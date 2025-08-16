<?php

use Agavi\AgaviContext;
use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Translation\AgaviDateFormatter;

// Base class for calendar-related tests (renamed *.base.php to avoid PHPUnit collecting it directly)
class BaseCalendarTest extends AgaviPhpUnitTestCase 
{
	protected $tm;

	public function setUp(): void
	{
		parent::setUp();
		$this->tm = AgaviContext::getInstance('testing')->getTranslationManager();
	}

	protected function date($y, $m, $d, $hr = 0, $min = 0, $sec = 0)
	{
		$cal = $this->tm->createCalendar();
		$cal->clear();
		$cal->set(1900 + $y, $m, $d, $hr, $min, $sec); // Add 1900 to follow java.util.Date protocol
		return $cal->getTime();
	}

	protected function dateToString($time)
	{
		if(is_numeric($time)) {
			$cal = $this->tm->createCalendar();
			$cal->setTime($time);
			$time = $cal;
		}
		$time->getTime();
		$format = new AgaviDateFormatter('EEE MMM dd HH:mm:ss zzz yyyy');
		return $format->format($time, 'gregorian', $this->tm->getCurrentLocale());
	}
}
