<?php

use Quiote\Context;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Translation\DateFormatter;

abstract class BaseCalendarTest extends PhpUnitTestCase 
{
	protected $tm;

	public function setUp(): void
	{
		parent::setUp();
		$this->tm = Context::getInstance('testing')->getTranslationManager();
	}

	protected function date($y, $m, $d, $hr = 0, $min = 0, $sec = 0)
	{
		$cal = $this->tm->createCalendar();
		$cal->clear();
		$cal->set(1900 + $y, $m, $d, $hr, $min, $sec); // Add 1900 to follow java.util.Date protocol
		$dt = $cal->getTime();
		return $dt;
	}

	// TODO: implement this stuff
	protected function dateToString($time)
	{
		if(is_numeric($time)) {
			$cal = $this->tm->createCalendar();
			$cal->setTime($time);
			$time = $cal;
		}
		$time->getTime();
		$format = new DateFormatter('EEE MMM dd HH:mm:ss zzz yyyy');
		return $format->format($time, 'gregorian', $this->tm->getCurrentLocale());
	}

}
