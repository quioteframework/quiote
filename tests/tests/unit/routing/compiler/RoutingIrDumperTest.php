<?php

use Quiote\Config\Config;
use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Routing\Compiler\RouteDefinition;
use Quiote\Routing\Compiler\RoutePlan;
use Quiote\Routing\Compiler\RoutingIrDumper;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Covers item 6 of PERF_PLAN.md: RoutingIrDumper::emit()/load() round-trips a
 * RoutePlan through an opcache-friendly `return [...]` PHP artifact, so
 * AttributeRouting::build() can skip AttributeRouteScanner's live scan.
 */
class RoutingIrDumperTest extends PhpUnitTestCase
{
    /** @var list<string> */
    private array $filesToDelete = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->filesToDelete as $file) {
            @unlink($file);
        }
        $this->filesToDelete = [];
        parent::tearDown();
    }

    private function writeArtifact(\Quiote\Support\Compiler\EmittedArtifact $artifact): string
    {
        $dir = dirname($artifact->targetHint);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($artifact->targetHint, $artifact->phpSource);
        $this->filesToDelete[] = $artifact->targetHint;
        return $artifact->targetHint;
    }

    public function testEmitAndLoadRoundTripsRealScannedRoutes(): void
    {
        $scanner = new AttributeRouteScanner();
        $plan = $scanner->scan([Config::getString('core.module_dir')]);
        $this->assertNotEmpty($plan->routes, 'precondition: the sandbox module dir must yield at least one #[Route]');

        $artifact = RoutingIrDumper::emit($plan);
        $this->writeArtifact($artifact);

        // load() always reads from targetFor()'s fixed, input-derived path,
        // not an arbitrary one -- so the artifact must land exactly there for
        // load() to see it.
        $this->assertSame(RoutingIrDumper::targetFor(), $artifact->targetHint);

        $loaded = RoutingIrDumper::load();
        $this->assertInstanceOf(RoutePlan::class, $loaded);
        $this->assertSame($plan->sourceRef, $loaded->sourceRef);
        $this->assertCount(count($plan->routes), $loaded->routes);

        $byName = [];
        foreach ($loaded->routes as $route) {
            $byName[$route->name] = $route;
        }
        foreach ($plan->routes as $original) {
            $this->assertArrayHasKey($original->name, $byName);
            $reloaded = $byName[$original->name];
            $this->assertSame($original->path, $reloaded->path);
            $this->assertSame($original->module, $reloaded->module);
            $this->assertSame($original->action, $reloaded->action);
            $this->assertSame($original->methods, $reloaded->methods);
            $this->assertSame($original->defaults, $reloaded->defaults);
            $this->assertSame($original->requirements, $reloaded->requirements);
            $this->assertSame($original->host, $reloaded->host);
            $this->assertSame($original->condition, $reloaded->condition);
            $this->assertSame($original->priority, $reloaded->priority);
            $this->assertSame($original->outputType, $reloaded->outputType);
            $this->assertSame($original->meta, $reloaded->meta);
        }
    }

    public function testLoadReturnsNullWhenNoArtifactExists(): void
    {
        $path = RoutingIrDumper::targetFor();
        if (is_file($path)) {
            unlink($path);
        }

        $this->assertNull(RoutingIrDumper::load());
    }

    public function testLoadReturnsNullForMalformedArtifact(): void
    {
        $path = RoutingIrDumper::targetFor();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, "<?php\nreturn ['not' => 'the expected shape'];\n");
        $this->filesToDelete[] = $path;

        $this->assertNull(RoutingIrDumper::load());
    }

    public function testTargetForDiffersWhenModuleDirDiffers(): void
    {
        $original = RoutingIrDumper::targetFor();

        Config::set('core.module_dir', Config::getString('core.module_dir') . '/does-not-exist', true);
        try {
            $differing = RoutingIrDumper::targetFor();
        } finally {
            // Restore: Config::set() with overwrite=true just replaces, no
            // dedicated "previous value" API, so recompute from the fixture
            // path used everywhere else in this suite would be brittle --
            // reconstruct it the same way tests/bootstrap.php does instead.
            Config::set('core.module_dir', Config::getString('core.app_dir') . '/Modules', true);
        }

        $this->assertNotSame($original, $differing);
    }
}
