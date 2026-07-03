<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Routing\Routing;
use Quiote\Routing\Compiler\CompiledMatcherDumper;
use Quiote\Support\Compiler\FilesystemArtifactWriter;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Guards perf change B2: a dumped Symfony CompiledUrlMatcher must be loaded
 * when present, produce identical match results to the dynamic UrlMatcher, and
 * safely fall back to the dynamic matcher when the dump is absent, stale (route
 * table changed => different signature => no matching file), or disabled.
 */
class CompiledMatcherParityTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/quiote_compiled_matcher_test_' . getmypid();
        Config::set('core.cache_dir', $this->cacheDir);
        Config::set('core.routing.compiled_matcher', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/routing/*.php') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function makeRouting(array $spec): Routing
    {
        return new class($spec) extends Routing {
            public function __construct(private readonly array $spec) { parent::__construct(); }
            protected function build(): array {
                $rc = new RouteCollection();
                foreach ($this->spec as $name => $r) {
                    $rc->add($name, new Route($r['pattern'], $r['defaults'] ?? [], $r['requirements'] ?? []));
                }
                return [$rc, []];
            }
        };
    }

    private function matcherOf(Routing $routing): object
    {
        $prop = new \ReflectionProperty(Routing::class, 'matcher');
        return $prop->getValue($routing);
    }

    private const SPEC = [
        'home'    => ['pattern' => '/home', 'defaults' => ['module' => 'Main', 'action' => 'Index']],
        'user'    => ['pattern' => '/user/{id}', 'defaults' => ['module' => 'User', 'action' => 'Show'], 'requirements' => ['id' => '\d+']],
        'contact' => ['pattern' => '/contact', 'defaults' => ['module' => 'Main', 'action' => 'Contact']],
    ];

    public function testUsesDynamicMatcherWhenNoDumpPresent(): void
    {
        $routing = $this->makeRouting(self::SPEC);
        $this->assertInstanceOf(UrlMatcher::class, $this->matcherOf($routing));
        $this->assertNotInstanceOf(CompiledUrlMatcher::class, $this->matcherOf($routing));
    }

    public function testLoadsCompiledMatcherAndMatchesIdentically(): void
    {
        $dynamic = $this->makeRouting(self::SPEC);
        $artifact = CompiledMatcherDumper::emit($dynamic->getRouteCollection());
        (new FilesystemArtifactWriter())->write($artifact, $artifact->targetHint);

        $compiled = $this->makeRouting(self::SPEC);
        $this->assertInstanceOf(CompiledUrlMatcher::class, $this->matcherOf($compiled), 'compiled matcher should be loaded when a matching dump exists');

        foreach (['/home', '/user/42', '/contact'] as $path) {
            // Same keys/values; the two matchers just order _route differently,
            // and consumers read keys by name (RoutingMiddleware), so compare
            // order-independently.
            $this->assertEqualsCanonicalizing(
                $dynamic->match($path),
                $compiled->match($path),
                "compiled and dynamic matchers must agree on $path"
            );
        }
        // Requirement enforced identically (id must be digits).
        $this->expectException(ResourceNotFoundException::class);
        $compiled->match('/user/abc');
    }

    public function testStaleDumpFallsBackToDynamic(): void
    {
        // Dump for one route table...
        $dynamic = $this->makeRouting(self::SPEC);
        $artifact = CompiledMatcherDumper::emit($dynamic->getRouteCollection());
        (new FilesystemArtifactWriter())->write($artifact, $artifact->targetHint);

        // ...then build a DIFFERENT route table. Its signature differs, so no
        // dumped file matches and it must fall back to the dynamic matcher.
        $changed = $this->makeRouting([
            'home' => ['pattern' => '/home', 'defaults' => ['module' => 'Main', 'action' => 'Index']],
            'blog' => ['pattern' => '/blog/{slug}', 'defaults' => ['module' => 'Blog', 'action' => 'Show']],
        ]);
        $this->assertNotInstanceOf(CompiledUrlMatcher::class, $this->matcherOf($changed));
        $m = $changed->match('/blog/hello');
        $this->assertSame('Blog', $m['module']);
        $this->assertSame('hello', $m['slug']);
    }

    public function testDisabledFlagForcesDynamicMatcher(): void
    {
        $dynamic = $this->makeRouting(self::SPEC);
        $artifact = CompiledMatcherDumper::emit($dynamic->getRouteCollection());
        (new FilesystemArtifactWriter())->write($artifact, $artifact->targetHint);

        Config::set('core.routing.compiled_matcher', false);
        $routing = $this->makeRouting(self::SPEC);
        $this->assertNotInstanceOf(CompiledUrlMatcher::class, $this->matcherOf($routing));
    }
}
