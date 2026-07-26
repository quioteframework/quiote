<?php

use Quiote\Config\Config;
use Quiote\Routing\AttributeRouting;
use Quiote\Routing\Compiler\RouteDefinition;
use Quiote\Routing\Compiler\RoutePlan;
use Quiote\Routing\Compiler\RoutingIrDumper;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Covers item 6 of PERF_PLAN.md: under core.routing.trust_compiled_ir,
 * AttributeRouting::build() loads a previously dumped routing IR artifact
 * instead of running AttributeRouteScanner's live scan. Each test uses a
 * synthetic route that could only come from the artifact (no such module
 * exists on disk), so a match succeeding is direct proof the live scan was
 * skipped -- not just that both paths happen to agree.
 */
class AttributeRoutingCompiledIrTest extends PhpUnitTestCase
{
    /** @var list<string> */
    private array $filesToDelete = [];

    #[\Override]
    protected function tearDown(): void
    {
        Config::remove('core.routing.trust_compiled_ir');
        foreach ($this->filesToDelete as $file) {
            @unlink($file);
        }
        $this->filesToDelete = [];
        parent::tearDown();
    }

    private function fakeRoutePlan(): RoutePlan
    {
        $route = new RouteDefinition(
            'fake.route.from.ir',
            '/fake-from-ir',
            'FakeIrModule',
            'FakeIrAction',
            ['GET'],
            [],
            [],
            null,
            null,
            0,
            null,
            ['gen_path' => '/fake-from-ir', 'cut' => false, 'path' => '/fake-from-ir'],
            'synthetic-test-fixture',
        );
        return new RoutePlan([$route], 'synthetic-test-fixture');
    }

    private function writeFakeArtifact(): void
    {
        $artifact = RoutingIrDumper::emit($this->fakeRoutePlan());
        $dir = dirname($artifact->targetHint);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($artifact->targetHint, $artifact->phpSource);
        $this->filesToDelete[] = $artifact->targetHint;
    }

    private function makeDefaultAttributeRouting(): AttributeRouting
    {
        // No moduleDirs() override: exercises the exact default path
        // AttributeRouting::build() gates the compiled-IR lookup on.
        return new class extends AttributeRouting {
        };
    }

    public function testBuildUsesCompiledIrArtifactWhenTrustFlagEnabled(): void
    {
        $this->writeFakeArtifact();
        Config::set('core.routing.trust_compiled_ir', true, true);

        $routing = $this->makeDefaultAttributeRouting();
        $attributes = $routing->match('/fake-from-ir');

        $this->assertSame('FakeIrModule', $attributes['_module']);
        $this->assertSame('FakeIrAction', $attributes['_action']);
    }

    public function testBuildIgnoresArtifactWhenTrustFlagDisabledByDefault(): void
    {
        $this->writeFakeArtifact();
        // core.routing.trust_compiled_ir left at its default (false/unset).

        $routing = $this->makeDefaultAttributeRouting();

        // The fake route only exists in the artifact; with the trust flag
        // off, a live scan runs instead and never finds it.
        $this->expectException(\Symfony\Component\Routing\Exception\ResourceNotFoundException::class);
        $routing->match('/fake-from-ir');
    }

    public function testBuildFallsBackToLiveScanWhenTrustFlagEnabledButNoArtifactExists(): void
    {
        $path = RoutingIrDumper::targetFor();
        if (is_file($path)) {
            unlink($path);
        }
        Config::set('core.routing.trust_compiled_ir', true, true);

        $routing = $this->makeDefaultAttributeRouting();

        // No artifact to trust -> live scan runs -> real AttrRouting fixture
        // routes (see AttributeRouteScannerTest) are still found.
        $attributes = $routing->match('/attr-routing');
        $this->assertSame('AttrRouting', $attributes['_module']);
        $this->assertSame('List', $attributes['_action']);
    }

    public function testBuildIgnoresArtifactWhenModuleDirsIsOverridden(): void
    {
        $this->writeFakeArtifact();
        Config::set('core.routing.trust_compiled_ir', true, true);

        $moduleDir = Config::getString('core.module_dir');
        $routing = new class($moduleDir) extends AttributeRouting {
            public function __construct(private readonly string $moduleDir)
            {
                parent::__construct();
            }

            #[\Override]
            protected function moduleDirs(): iterable
            {
                return [$this->moduleDir];
            }
        };

        // A custom moduleDirs() is never represented by the artifact (which
        // is keyed by the *default* scan inputs), so it must always live-scan
        // regardless of the trust flag.
        $this->expectException(\Symfony\Component\Routing\Exception\ResourceNotFoundException::class);
        $routing->match('/fake-from-ir');
    }
}
