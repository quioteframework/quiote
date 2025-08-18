<?php

use Agavi\Testing\AgaviUnitTestCase;

class AgaviLocaleTest extends AgaviUnitTestCase
{
	protected function setUp(): void
	{
		$this->markTestSkipped('Translation/i18n subsystem pending rewrite');
	}

	public function testGetCalendarDayWide()
	{
		$locale = $this->getContext()->getTranslationManager()->getLocale('en_US');
		
		$expected = array(
			1 => 'Sunday',
			2 => 'Monday', 
			3 => 'Tuesday',
			4 => 'Wednesday',
			5 => 'Thursday',
			6 => 'Friday',
			7 => 'Saturday',
		);
		$this->assertEquals($expected, $locale->getCalendarDaysWide('gregorian'));
	}
}


?>