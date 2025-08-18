<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Date\AgaviTimeZone;

class Ticket958Test extends AgaviUnitTestCase
{
	public function testTicket958()
	{
		$this->markTestSkipped('Timezone custom creation depends on translation/i18n system slated for rewrite.');
		$this->expectException(\InvalidArgumentException::class);
		$tm = $this->getContext()->getTranslationManager();
		$tz = AgaviTimeZone::createCustomTimeZone($tm, '+01:00');
	}
}

?>