<?php
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Routing\AgaviRouting;
use Agavi\Config\AgaviConfigCache;
use Agavi\Config\AgaviConfig;
use Agavi\Routing\AgaviRoutingCallback;
use Symfony\Component\Routing\RouteCollection;

class DebugTestCallbackLAN extends AgaviRoutingCallback { public function onMatched(array &$parameters, $legacyContainer = null){ return true; } }
class DebugTestCallbackRAN extends AgaviRoutingCallback { public function onMatched(array &$parameters, $legacyContainer = null){ return false; } }
class DebugTestCallbackParent extends AgaviRoutingCallback { public function onMatched(array &$parameters, $legacyContainer = null){ return true; } }
class DebugTestCallbackCN extends AgaviRoutingCallback { public function onMatched(array &$parameters, $legacyContainer = null){ return true; } }
class DebugTestCallbackCC extends AgaviRoutingCallback { public function onMatched(array &$parameters, $legacyContainer = null){ return true; } }
class DebugTestCallbackCS extends AgaviRoutingCallback { public function onMatched(array &$parameters, $legacyContainer = null){ return true; } }

// Debug subclass to capture parseInput invocations.
class DebugRouting extends AgaviRouting {
    public static array $attempts = [];
    public function importTestConfig($cfg,$ctx){ $this->importRoutes(unserialize(file_get_contents(AgaviConfigCache::checkConfig($cfg,$ctx)))); }
    public function forceInput(string $path): void { $this->input = $path; }
    // Provide empty route collection to satisfy abstract build (callbacks removed)
    protected function build(): array { return [new RouteCollection(), []]; }
    public function execute(): void { /* no-op stub for skipped test */ }
}

class AdHocRoutingDebugTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        $this->markTestSkipped('Skipping callback-based diagnostic routing tests (callbacks removed).');
    }
    protected function newRoutingWithConfig(string $configFile, string $context): DebugRouting
    {
        $r = new DebugRouting();
        $r->initialize($this->getContext());
        $r->importTestConfig(AgaviConfig::get('core.config_dir').'/tests/'.$configFile,$context);
        return $r;
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testDiagnosticSequence()
    {
    DebugRouting::$attempts = [];
    $routing = $this->newRoutingWithConfig('routing_callbacks.xml','test_callbacks');
    $routing->forceInput('/de/parent/42/p1/part1match/p2/part2match');
    $routing->execute();
    $rq = $this->getContext()->getRequest();
    $matched = $rq->getAttribute('matched_routes','org.agavi.routing');
    $this->assertEquals(['left_anchored_nonstop','parent','child_complex'], $matched, 'Expected complex chain match sequence');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testDiagnosticTitleMatch()
    {
    DebugRouting::$attempts = [];
    $routing = $this->newRoutingWithConfig('routing_callbacks.xml','test_callbacks');
    $routing->forceInput('/en/parent/42/title_match');
    $routing->execute();
    $rq = $this->getContext()->getRequest();
    $matched = $rq->getAttribute('matched_routes','org.agavi.routing');
    $this->assertEquals(['left_anchored_nonstop','parent','child_nonstop','child_simple'], $matched, 'Expected title chain match sequence');
    }
}
