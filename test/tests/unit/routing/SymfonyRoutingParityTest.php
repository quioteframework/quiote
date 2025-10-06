<?php

use PHPUnit\Framework\TestCase;
use Agavi\Routing\AgaviRouting;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

/**
 * Parity tests for the new Symfony-based routing layer replacing legacy
 * container + callback execution model. Focuses on core behaviors we still
 * guarantee: default module/action storage, parameter substitution, optional
 * placeholders, default omission, self URL generation (null route) and
 * error handling for unknown routes.
 */
class SymfonyRoutingParityTest extends TestCase
{
    private ParityRouting $routing;

    protected function setUp(): void
    {
        // Ensure script name empty so self-generation is clean
        $_SERVER['SCRIPT_NAME'] = '';
        $_SERVER['REQUEST_URI'] = '/';
        $this->routing = new ParityRouting();
    }

    public function testIndexGeneration(): void
    {
        $this->assertSame('/', $this->routing->gen('index'));    
    }

    public function testParameterSubstitution(): void
    {
        $this->assertSame('/item/42', $this->routing->gen('with_param', ['id' => 42]));
    }

    public function testMultipleParametersAndEncoding(): void
    {
        $url = $this->routing->gen('with_two', ['id' => 5, 'slug' => 'needs encoding /']);
        $this->assertSame('/multi/5/needs%20encoding%20%2F', $url);
    }

    public function testOptionalPlaceholderOmittedByEmptyDefault(): void
    {
        // slug has default '' so it collapses
        $this->assertSame('/blog', $this->routing->gen('blog_show'));
        $this->assertSame('/blog/hello', $this->routing->gen('blog_show', ['slug' => 'hello']));
    }

    public function testOmitDefaultsRemovesTrailingDefaults(): void
    {
        // category=all, page=1 defaults – both trimmed
        $this->assertSame('/product', $this->routing->gen('product_list', [], ['omit_defaults' => true]));
        // Override one default -> remaining default(s) still considered
        $this->assertSame('/product/books', $this->routing->gen('product_list', ['category' => 'books'], ['omit_defaults' => true]));
        // Override trailing default page keeps explicit page
        $this->assertSame('/product/books/2', $this->routing->gen('product_list', ['category' => 'books', 'page' => 2], ['omit_defaults' => true]));
    }

    public function testSelfUrlGenerationAndQueryParamRemoval(): void
    {
        $this->routing->setInput('/items/5');
        $self = $this->routing->gen(null, ['foo' => 'bar']);
        $this->assertSame('/items/5?foo=bar', $self);
        // Setting foo => null removes it from query string
        $selfRemoved = $this->routing->gen(null, ['foo' => null]);
        $this->assertSame('/items/5', $selfRemoved);
    }

    public function testUnknownRouteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->routing->gen('missing_route');
    }
}

/**
 * Minimal concrete AgaviRouting for the parity tests.
 * We manually construct RouteCollection + meta mirroring legacy structure
 * required by AgaviRouting::gen().
 */
class ParityRouting extends AgaviRouting
{
    // Expose ability to alter current input (used for self URL gen tests)
    public function setInput(string $path): void { $this->input = $path; }

    protected function build(): array
    {
        $rc = new RouteCollection();
        $meta = [];

        $add = function(string $name, string $pattern, array $defaults = []) use ($rc, &$meta) {
            $rc->add($name, new Route($pattern, $defaults));
            $meta[$name] = [
                'gen_path' => $pattern,
                'cut' => false,
                'path' => $pattern,
                'match_full' => '#^' . preg_quote(trim($pattern,'/'), '#') . '$#',
                'match_partial' => '#^' . preg_quote(trim($pattern,'/'), '#') . '#',
                'opt' => [
                    'parent' => null,
                    'action' => $defaults['action'] ?? null,
                ],
            ];
        };

        // Basic index
        $add('index', '/', ['module' => 'Default', 'action' => 'Index']);
        // Single parameter route
        $add('with_param', '/item/{id}', ['module' => 'Item', 'action' => 'Show']);
        // Multi parameter requiring encoding of space and slash
        $add('with_two', '/multi/{id}/{slug}', ['module' => 'Multi', 'action' => 'Show']);
        // Optional slug via empty default (collapses)
        $add('blog_show', '/blog/{slug}', ['module' => 'Blog', 'action' => 'Show', 'slug' => '']);
        // Defaults trimming demonstration
        $add('product_list', '/product/{category}/{page}', [
            'module' => 'Catalog', 'action' => 'List', 'category' => 'all', 'page' => '1'
        ]);

        return [$rc, $meta];
    }
}

?>