<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Routing\AgaviOptimizedWebRouting;
use Agavi\AgaviContext;

class AgaviOptimizedRoutingBehaviorTest extends AgaviUnitTestCase
{
    private AgaviOptimizedWebRouting $routing;

    protected function setUp(): void
    {
    $this->markTestSkipped('AgaviOptimizedWebRouting removed (Symfony routing migration).');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testIndexRoute() { $this->fail('Skipped'); }

    public function testParamExtraction() { $this->fail('Skipped'); }

    public function testMultipleParamExtraction() { $this->fail('Skipped'); }

    public function testHierarchyChildRoute() { $this->fail('Skipped'); }

    public function testImpliedRouteInclusion() { $this->fail('Skipped'); }

    public function testOutputTypeAndLocale() { $this->fail('Skipped'); }

    public function testMethodConstraintRejectsWrongMethod() { $this->fail('Skipped'); }

    public function testMethodConstraintAcceptsWrite() { $this->fail('Skipped'); }

    public function testMethodTransformation() { $this->fail('Skipped'); }

    public function testGetAffectedRoutesWithImply() { $this->fail('Skipped'); }
}
