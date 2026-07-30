<?php

use PHPUnit\Framework\TestCase;
use Quiote\Exception\QuioteException;
use Quiote\Routing\Routing;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

class RoutingAdditionalTest extends TestCase
{
    /**
     * @param array<string, array{pattern: string, defaults?: array<string, mixed>}> $spec
     */
    private function routing(array $spec): Routing
    {
        return new class($spec) extends Routing {
            /**
             * @param array<string, array{pattern: string, defaults?: array<string, mixed>}> $spec
             */
            public function __construct(private readonly array $spec) { parent::__construct(); }
            protected function build(): array {
                $rc = new RouteCollection();
                $meta = [];
                foreach ($this->spec as $name => $r) {
                    $pattern = $r['pattern'];
                    $defaults = $r['defaults'] ?? [];
                    $rc->add($name, new Route($pattern, $defaults));
                    $meta[$name] = [
                        'gen_path' => $pattern,
                        'cut' => false,
                        'path' => $pattern,
                        'match_full' => '#^' . trim((string) $pattern,'^') . '$#',
                        'match_partial' => '#^' . trim((string) $pattern,'^') . '#',
                        'opt' => [
                            'parent' => null,
                            'action' => $defaults['action'] ?? null,
                        ]
                    ];
                }
                return [$rc, $meta];
            }
        };
    }

    public function testBaseHrefWithForwardedHeaders(): void
    {
        $routing = $this->routing([]);
        $prevHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null;
        $prevProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'example.org';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $href = $routing->getBaseHref();
        $this->assertSame('https://example.org', $href);
        if ($prevHost === null) { unset($_SERVER['HTTP_X_FORWARDED_HOST']); } else { $_SERVER['HTTP_X_FORWARDED_HOST'] = $prevHost; }
        if ($prevProto === null) { unset($_SERVER['HTTP_X_FORWARDED_PROTO']); } else { $_SERVER['HTTP_X_FORWARDED_PROTO'] = $prevProto; }
    }

    public function testBaseHrefIgnoresNonStringServerHostValueAndFallsBackToLocalhost(): void
    {
        $routing = $this->routing([]);
        $prevHost = $_SERVER['HTTP_HOST'] ?? null;
        $prevName = $_SERVER['SERVER_NAME'] ?? null;
        $prevAddr = $_SERVER['SERVER_ADDR'] ?? null;
        // Simulate a malformed/tampered SAPI environment where a header value
        // is an array rather than a string; the host resolution must degrade
        // to the "localhost" fallback rather than fatally erroring.
        $_SERVER['HTTP_HOST'] = ['not', 'a', 'string'];
        unset($_SERVER['SERVER_NAME'], $_SERVER['SERVER_ADDR']);

        $href = $routing->getBaseHref();

        $this->assertSame('http://localhost', $href);

        if ($prevHost === null) { unset($_SERVER['HTTP_HOST']); } else { $_SERVER['HTTP_HOST'] = $prevHost; }
        if ($prevName === null) { unset($_SERVER['SERVER_NAME']); } else { $_SERVER['SERVER_NAME'] = $prevName; }
        if ($prevAddr === null) { unset($_SERVER['SERVER_ADDR']); } else { $_SERVER['SERVER_ADDR'] = $prevAddr; }
    }

    public function testGenStarSuffixRefillFlagNoop(): void
    {
        $routing = $this->routing([
            'file' => ['pattern' => '/f/{name}', 'defaults' => ['name' => 'def']],
        ]);
        $url = $routing->gen('file*', ['name' => 'abc']);
        $this->assertSame('/f/abc', $url);
    }

    public function testGenOmitDefaultsStopsOnNonDefaultSegment(): void
    {
        $routing = $this->routing([
            'combo' => ['pattern' => '/a/{x}/{y}/{z}', 'defaults' => ['x' => 'dx', 'y' => 'dy', 'z' => 'dz']],
        ]);
        $full = $routing->gen('combo');
        $this->assertSame('/a/dx/dy/dz', $full);
        // Provide explicit y differing from default so pruning should keep x (left) but remove trailing default z only.
        $mixed = $routing->gen('combo', ['y' => 'Y!'], ['omit_defaults' => true]);
        $this->assertSame('/a/dx/Y!/dz', $mixed, 'Non-rightmost differing segment prevents pruning of earlier defaults.');
    }

    public function testParseRouteStringExtractsTokens(): void
    {
        $routing = $this->routing([]);
        [$pattern, $orig, $vars] = $routing->parseRouteString('/file/{name}/{ext}');
        $this->assertArrayHasKey('name', $vars);
        $this->assertArrayHasKey('ext', $vars);
        $this->assertSame('/file/{name}/{ext}', $orig);
    }

    public function testGenSelfMergesQueryOverrides(): void
    {
        $routing = $this->routing([]);
        $prevScript = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $ref = new ReflectionClass($routing);
        $prop = $ref->getProperty('input');
        // $prop->setAccessible(true); // Deprecated, not needed in PHP 8.1+
        $prop->setValue($routing, '/path');
        $url = $routing->genSelf(null, ['a' => '1'], ['b' => '2']);
        $this->assertStringStartsWith('/index.php/path?', $url);
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $qArr);
        ksort($qArr);
        $this->assertSame(['a' => '1', 'b' => '2'], $qArr, 'Query params should contain both overrides irrespective of ordering');
        if ($prevScript === null) { unset($_SERVER['SCRIPT_NAME']); } else { $_SERVER['SCRIPT_NAME'] = $prevScript; }
    }

    public function testAddRouteWithNonStringNameThrows(): void
    {
        $routing = $this->routing([]);
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage("Route option 'name' must be a string.");
        $routing->addRoute('/foo', ['name' => 123]);
    }

    public function testAddRouteWithNonArrayDefaultsThrows(): void
    {
        $routing = $this->routing([]);
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage("Route option 'defaults' must be an array.");
        $routing->addRoute('/foo', ['defaults' => 'not-an-array']);
    }

    public function testAddRouteWithNonStringDefaultsKeyThrows(): void
    {
        $routing = $this->routing([]);
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage("Route option 'defaults' keys must be strings.");
        $routing->addRoute('/foo', ['defaults' => ['action']]);
    }

    public function testAddRouteWithValidOptsSucceeds(): void
    {
        $routing = $this->routing([]);
        $name = $routing->addRoute('/foo', ['name' => 'foo_route', 'module' => 'M', 'action' => 'A']);
        $this->assertSame('foo_route', $name);
        $route = $routing->getRoute('foo_route');
        $this->assertNotNull($route);
        $this->assertSame('/foo', $route['path']);
    }

    public function testGenOmitDefaultsWithNonScalarNonStringableParamThrows(): void
    {
        $routing = $this->routing([
            'combo' => ['pattern' => '/a/{x}', 'defaults' => ['x' => 'dx']],
        ]);
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('Route parameter value must be scalar or Stringable');
        $routing->gen('combo', ['x' => ['not', 'scalar']], ['omit_defaults' => true]);
    }

    public function testImportRoutesWithValidTupleRoundTrips(): void
    {
        $routing = $this->routing([
            'home' => ['pattern' => '/home', 'defaults' => ['action' => 'Index']],
        ]);
        $spec = $routing->exportRoutes();

        $target = $this->routing([]);
        $target->importRoutes($spec);

        $this->assertSame('/home', $target->gen('home'));
    }

    public function testImportRoutesWithNonArrayMetaEntryThrows(): void
    {
        $routing = $this->routing([]);
        $rc = new RouteCollection();
        $rc->add('bad', new Route('/bad'));
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage("Route meta entry for 'bad' must be an array.");
        $routing->importRoutes([$rc, ['bad' => 'not-an-array']]);
    }

    public function testImportRoutesWithMissingRequiredMetaFieldThrows(): void
    {
        $routing = $this->routing([]);
        $rc = new RouteCollection();
        $rc->add('bad', new Route('/bad'));
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage("Route meta entry for 'bad' has invalid or missing required fields.");
        $routing->importRoutes([$rc, ['bad' => ['gen_path' => '/bad', 'cut' => false]]]);
    }
}
