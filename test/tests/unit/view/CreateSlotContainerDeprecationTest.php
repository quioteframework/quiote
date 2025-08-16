<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

class CreateSlotContainerDeprecationTest extends AgaviUnitTestCase {
    protected function setUp(): void { parent::setUp(); $this->markTestSkipped('createSlotContainer deprecated & replaced by createSlotContent.'); }
    public function testCreateSlotContainerEmitsDeprecation() { $this->fail('Skipped'); }
}
