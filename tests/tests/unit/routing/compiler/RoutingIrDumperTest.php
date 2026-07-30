<?php

use Quiote\Config\Config;
use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Routing\Compiler\RouteDefinition;
use Quiote\Routing\Compiler\RoutePlan;
use Quiote\Routing\Compiler\RoutingIrDumper;
use Quiote\Plugin\PluginManager;
use Quiote\Testing\PhpUnitTestCase;

/**
 * RoutingIrDumper::emit()/load() round-trips a RoutePlan through an
 * opcache-friendly `return [...]` PHP artifact, so AttributeRouting::build()
 * can skip AttributeRouteScanner's live scan.
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

    public function testTargetForDiffersWhenTheModuleDirectorySetDiffers(): void
    {
        // The artifact path keys on the full set of module directories, so a
        // plugin contributing one has to land on a different target than the
        // app's own directory alone -- otherwise two differently-composed apps
        // would fight over one cache file.
        //
        // Varied through PluginManager rather than by rewriting
        // `core.module_dir`: several suites pin that directive readonly (the 4th
        // argument to Config::set()), which is a process-lifetime latch that
        // silently discards later writes, so this test's outcome would depend on
        // whether one of those ran first.
        $original = RoutingIrDumper::targetFor();

        $contributed = [];
        foreach (PluginManager::moduleDirectories() as $dir) {
            $contributed[] = $dir;
        }
        try {
            PluginManager::addModuleDirectory(Config::getString('core.module_dir') . '/does-not-exist');
            $differing = RoutingIrDumper::targetFor();
        } finally {
            $ro = new \ReflectionClass(PluginManager::class);
            $prop = $ro->getProperty('moduleDirs');
            $prop->setValue(null, $contributed);
        }

        $this->assertNotSame($original, $differing);
        $this->assertSame($original, RoutingIrDumper::targetFor(), 'the original target must come back once the contribution is gone');
    }
}
