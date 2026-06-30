<?php

use PHPUnit\Framework\TestCase;
use Agavi\Routing\AgaviRouting;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

class AgaviRoutingAdditionalTest extends TestCase
{
    private function routing(array $spec): AgaviRouting
    {
        return new class($spec) extends AgaviRouting {
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

    public function testBaseHrefWithForwardedHeaders()
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

    public function testGenStarSuffixRefillFlagNoop()
    {
        $routing = $this->routing([
            'file' => ['pattern' => '/f/{name}', 'defaults' => ['name' => 'def']],
        ]);
        $url = $routing->gen('file*', ['name' => 'abc']);
        $this->assertSame('/f/abc', $url);
    }

    public function testGenOmitDefaultsStopsOnNonDefaultSegment()
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

    public function testParseRouteStringExtractsTokens()
    {
        $routing = $this->routing([]);
        [$pattern, $orig, $vars] = $routing->parseRouteString('/file/{name}/{ext}');
        $this->assertArrayHasKey('name', $vars);
        $this->assertArrayHasKey('ext', $vars);
        $this->assertSame('/file/{name}/{ext}', $orig);
    }

    public function testGenSelfMergesQueryOverrides()
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
}
