<?php

use PHPUnit\Framework\TestCase;
use Quiote\Routing\Routing;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Comprehensive routing tests scaffold.
 * Each test will target a specific dimension (matching, generation, hierarchy, defaults, optional params, priority, callbacks, trie stats, negative cases).
 */
class RoutingTest extends TestCase
{
    /**
     * Build a simple concrete routing instance for tests.
     */
    private function makeRouting(array $routesSpec): Routing
    {
        return new class($routesSpec) extends Routing {
            public function __construct(private readonly array $spec) { parent::__construct(); }
            protected function build(): array {
                $rc = new RouteCollection();
                $meta = [];
                foreach ($this->spec as $name => $r) {
                    $pattern = $r['pattern'];
                    $defaults = $r['defaults'] ?? [];
                    $route = new Route($pattern, $defaults);
                    $rc->add($name, $route);
                    $meta[$name] = [
                        'gen_path' => $pattern,
                        'cut' => false,
                        'path' => $pattern,
                        'match_full' => '#^' . trim((string) $pattern,'^') . '$#',
                        'match_partial' => '#^' . trim((string) $pattern,'^') . '#',
                        'opt' => [
                            'parent' => $r['parent'] ?? null,
                            'action' => $defaults['action'] ?? null,
                        ]
                    ];
                }
                return [$rc, $meta];
            }
        };
    }

    // Matching Scenarios --------------------------------------------------

    public function testMatchSimpleStaticRoute()
    {
        $routing = $this->makeRouting([
            'home' => ['pattern' => '/home', 'defaults' => ['module' => 'Main', 'action' => 'Index']]
        ]);
        $m = $routing->match('/home');
        $this->assertSame('Main', $m['module']);
        $this->assertSame('Index', $m['action']);
    }
    public function testMatchRouteWithDefaults()
    {
        $routing = $this->makeRouting([
            'user_show' => ['pattern' => '/user/{id}', 'defaults' => ['module' => 'User', 'action' => 'Show', 'id' => 42]]
        ]);
        $m = $routing->match('/user/42');
        $this->assertSame('42', (string)$m['id']);
        $this->assertSame('User', $m['module']);
        // When explicit value matches default ensure still present
        $this->assertSame('Show', $m['action']);
    }
    public function testMatchOptionalTrailingPlaceholderOmitted()
    {
        // In current implementation placeholders without provided param become empty string on generation;
        // For matching, Symfony Route requires the segment. Simulate optional by adding default and pattern without requirement.
        $routing = $this->makeRouting([
            'login' => ['pattern' => '/login/{type}', 'defaults' => ['module' => 'Auth', 'action' => 'Login', 'type' => '']] // default makes it optional-ish
        ]);
        // Matching '/login/' might normalize differently; ensure '/login' matches and id default filled.
        $m = $routing->match('/login');
        $this->assertSame('', $m['type']);
    }
    public function testMatchOptionalTrailingPlaceholderPresent()
    {
        $routing = $this->makeRouting([
            'login' => ['pattern' => '/login/{type}', 'defaults' => ['module' => 'Auth', 'action' => 'Login', 'type' => '']] 
        ]);
        $m = $routing->match('/login/basic');
        $this->assertSame('basic', $m['type']);
    }
    public function testMatchWildcardLikePatternSpecificityOrdering()
    {
        // Add two patterns that could both match; verify the matcher returns the first registered (our simple routing add order)
        $routing = $this->makeRouting([
            'specific' => ['pattern' => '/item/123', 'defaults' => ['module' => 'Item', 'action' => 'Show', 'id' => 123]],
            'generic' => ['pattern' => '/item/{id}', 'defaults' => ['module' => 'Item', 'action' => 'Show']]
        ]);
        $m = $routing->match('/item/123');
        // Symfony's RouteCollection resolves by definition order; ensure we got explicit default id 123
        $this->assertSame(123, $m['id']);
    }

    // Generation Scenarios ------------------------------------------------
    public function testGenerateSimple()
    {
        $routing = $this->makeRouting([
            'user_show' => ['pattern' => '/user/{id}', 'defaults' => ['module' => 'User', 'action' => 'Show', 'id' => 99]],
        ]);
        $url = $routing->gen('user_show', ['id' => 123]);
        $this->assertSame('/user/123', $url);
    }
    public function testGenerateWithDefaultsMerged()
    {
        $routing = $this->makeRouting([
            'user_show' => ['pattern' => '/user/{id}/{tab}', 'defaults' => ['module' => 'User', 'action' => 'Show', 'id' => 42, 'tab' => 'profile']],
        ]);
        // Provide only id; tab should come from defaults
        $url = $routing->gen('user_show', ['id' => 42]);
        $this->assertSame('/user/42/profile', $url);
    }
    public function testGenerateOptionalElidedWhenMissing()
    {
        $routing = $this->makeRouting([
            'login' => ['pattern' => '/login/{type}', 'defaults' => ['module' => 'Auth', 'action' => 'Login', 'type' => '']],
        ]);
        $url = $routing->gen('login', []);
        $this->assertSame('/login', $url);
    }
    public function testGenerateOptionalProvided()
    {
        $routing = $this->makeRouting([
            'login' => ['pattern' => '/login/{type}', 'defaults' => ['module' => 'Auth', 'action' => 'Login', 'type' => '']],
        ]);
        $url = $routing->gen('login', ['type' => 'basic']);
        $this->assertSame('/login/basic', $url);
    }
    public function testGenerateOmitDefaultsOptionPrunesRightmostSegments()
    {
        $routing = $this->makeRouting([
            'lang_page' => ['pattern' => '/lang/{lang}/{page}', 'defaults' => ['module' => 'Cms', 'action' => 'Index', 'lang' => 'en', 'page' => 'index']],
        ]);
        $full = $routing->gen('lang_page');
        $this->assertSame('/lang/en/index', $full);
        $pruned = $routing->gen('lang_page', [], ['omit_defaults' => true]);
        $this->assertSame('/lang', $pruned);
    }
    public function testGenerateNullRouteSelfWithParams()
    {
        $routing = $this->makeRouting([]);
        // Set current script and input path
        $prevScript = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        // Reflectively set input path
        $ref = new ReflectionClass($routing);
        $prop = $ref->getProperty('input');
        // $prop->setAccessible(true); // Deprecated, not needed in PHP 8.1+
        $prop->setValue($routing, '/current/path');
        $url = $routing->gen(null, ['q' => 'value']);
        $this->assertSame('/index.php/current/path?q=value', $url);
        if ($prevScript === null) { unset($_SERVER['SCRIPT_NAME']); } else { $_SERVER['SCRIPT_NAME'] = $prevScript; }
    }
    public function testGeneratePercentEncodingAndUnescapedOptions()
    {
        $routing = $this->makeRouting([
            'file_show' => ['pattern' => '/file/{name}', 'defaults' => ['module' => 'Fs', 'action' => 'Show']],
        ]);
        $url = $routing->gen('file_show', ['name' => 'spaced name']);
        $this->assertSame('/file/spaced%20name', $url, 'rawurlencode should be applied');
    }

    // Hierarchy -----------------------------------------------------------
    public function testAddRouteHierarchyConcatenation()
    {
        $routing = $this->makeRouting([]);
        $routing->addRoute('/api', ['name' => 'api_root', 'module' => 'Api', 'action' => 'Root']);
        $routing->addRoute('v1/users', ['name' => 'users', 'module' => 'Api', 'action' => 'Users'], 'api_root');
        $child = $routing->getRoute('users');
        $this->assertNotNull($child);
        $this->assertSame('/api/v1/users', $child['pattern']);
        $this->assertSame('api_root', $child['opt']['parent']);
    }
    public function testAddRouteDuplicateNameSameParentAllowed()
    {
        $routing = $this->makeRouting([]);
        $routing->addRoute('/foo/one', ['name' => 'dup', 'module' => 'M', 'action' => 'A']);
        // Overwrite with same parent (null) should succeed
        $routing->addRoute('/foo/two', ['name' => 'dup', 'module' => 'M', 'action' => 'A']);
        $r = $routing->getRoute('dup');
        $this->assertSame('/foo/two', $r['pattern']);
    }
    public function testAddRouteDuplicateNameDifferentParentThrows()
    {
        $routing = $this->makeRouting([]);
        $routing->addRoute('/p1', ['name' => 'p1', 'module' => 'M', 'action' => 'A']);
        $routing->addRoute('/p2', ['name' => 'p2', 'module' => 'M', 'action' => 'A']);
        $routing->addRoute('child', ['name' => 'child', 'module' => 'M', 'action' => 'A'], 'p1');
        $this->expectException(\Quiote\Exception\QuioteException::class);
        $routing->addRoute('child', ['name' => 'child', 'module' => 'M', 'action' => 'A'], 'p2');
    }


    // Negative Cases ------------------------------------------------------
    public function testGenerateUnknownRouteThrows()
    {
        $routing = $this->makeRouting([]);
        $this->expectException(InvalidArgumentException::class);
        $routing->gen('nope');
    }
    public function testGenerateMissingRequiredParamFallsBackEmpty()
    {
        $routing = $this->makeRouting([
            'user_show' => ['pattern' => '/user/{id}', 'defaults' => ['module' => 'User', 'action' => 'Show']],
        ]);
        $url = $routing->gen('user_show');
        // Placeholder removed -> /user
        $this->assertSame('/user', $url);
    }
    public function testMatchUnmatchedPathThrows()
    {
        $routing = $this->makeRouting([
            'home' => ['pattern' => '/home', 'defaults' => ['module' => 'Main', 'action' => 'Index']],
        ]);
        $this->expectException(ResourceNotFoundException::class);
        $routing->match('/absent');
    }

    // Constraints (if supported) -----------------------------------------
    public function testConstraintsMethodHostSchemeNotSupported()
    {
        $routing = $this->makeRouting([
            'home' => ['pattern' => '/home', 'defaults' => ['module' => 'Main', 'action' => 'Index']],
        ]);
        $rc = $routing->getRouteCollection();
        $r = $rc->get('home');
        // Symfony Route would expose host/schemes/methods if configured; they are not.
        $this->assertSame([], $r->getMethods());
        $this->assertSame('', $r->getHost());
        $this->assertSame([], $r->getSchemes());
    }
}
