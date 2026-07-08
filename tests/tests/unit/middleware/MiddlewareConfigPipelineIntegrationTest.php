<?php

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\SessionMiddleware;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Covers MiddlewarePipeline::mergeConfigDefinitions(): `middleware.xml`
 * (via MiddlewareConfigRegistry, the compiled target) can register a
 * brand-new middleware class with no #[Middleware] attribute at all, and
 * can override the placement of an already attribute-scanned app middleware
 * -- both landing in the resolved stack exactly where the config says.
 */
class MiddlewareConfigPipelineIntegrationTest extends TestCase
{
    public function setUp(): void
    {
        MiddlewareCatalog::initialize([]);
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
    }

    #[\Override]
    public function tearDown(): void
    {
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
    }

    /** @return list<string> */
    private function buildDebugStack(): array
    {
        $pipeline = new MiddlewarePipeline(Context::getInstance());
        $pipeline->handle(new ServerRequest('GET', 'http://localhost/test'));
        return $pipeline->debugStack();
    }

    public function testConfigDeclaredMiddlewareWithNoAttributeIsOrderedAndBuilt(): void
    {
        MiddlewareConfigRegistry::contribute([
            [
                'class' => ConfigOnlyFixtureMiddleware::class,
                'phase' => 'pre_routing',
                'priority' => null,
                'before' => SessionMiddleware::class,
                'after' => null,
                'enabled' => null,
                'override_framework' => false,
            ],
        ], 'test-middleware.xml');

        $order = $this->buildDebugStack();

        $this->assertContains(ConfigOnlyFixtureMiddleware::class, $order);
        $this->assertLessThan(
            array_search(SessionMiddleware::class, $order, true),
            array_search(ConfigOnlyFixtureMiddleware::class, $order, true)
        );
    }

    public function testConfigOverridesPlacementOfAnAttributeScannedAppMiddleware(): void
    {
        MiddlewareCatalog::registerAttributed(AttributeScannedFixtureMiddleware::class);

        // Without config, its own attribute puts it after RoutingMiddleware/before SecurityMiddleware.
        $baseline = $this->buildDebugStack();
        $this->assertLessThan(
            array_search(SecurityMiddleware::class, $baseline, true),
            array_search(AttributeScannedFixtureMiddleware::class, $baseline, true)
        );

        MiddlewareCatalog::reset();
        MiddlewareCatalog::registerAttributed(AttributeScannedFixtureMiddleware::class);
        MiddlewareConfigRegistry::contribute([
            [
                'class' => AttributeScannedFixtureMiddleware::class,
                'phase' => null,
                'priority' => null,
                'before' => null,
                'after' => null,
                'enabled' => false,
                'override_framework' => false,
            ],
        ], 'test-middleware.xml');

        $order = $this->buildDebugStack();
        $this->assertNotContains(AttributeScannedFixtureMiddleware::class, $order);
    }
}

#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', after: 'RoutingMiddleware', before: 'SecurityMiddleware')]
final class AttributeScannedFixtureMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

final class ConfigOnlyFixtureMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
