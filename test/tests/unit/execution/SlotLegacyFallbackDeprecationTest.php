<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\View\AgaviView;

class SlotLegacyFallbackDeprecationTest extends AgaviUnitTestCase
{
    protected function setUp(): void { parent::setUp(); $this->markTestSkipped('Legacy slot container fallback removed.'); }
    public function testLegacyFallbackTriggersDeprecation() { $this->fail('Skipped'); }
}
