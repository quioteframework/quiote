<?php

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginManager;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Testing\HttpTestCase;

/**
 * A test earlier in the process may have called PluginManager::reset(); HttpTestCase::setUp()
 * boots the configured plugins again so their middleware is back in the pipeline it dispatches
 * through, and leaves plugins that are already booted alone.
 */
final class HttpTestCasePluginBootTest extends HttpTestCase
{
    private mixed $originalPlugins = null;

    private bool $hadPlugins = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->hadPlugins = Config::has('plugins');
        $this->originalPlugins = Config::get('plugins');

        PluginManager::reset();
        HttpTestCaseFixturePlugin::$registrations = 0;
        Config::set('plugins', [HttpTestCaseFixturePlugin::class], true);
        if ($this->name() === 'testSetUpLeavesAlreadyBootedPluginsAlone') {
            PluginManager::bootFromConfig();
        }

        parent::setUp();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        PluginManager::reset();
        if ($this->hadPlugins) {
            Config::set('plugins', $this->originalPlugins, true);
        } else {
            Config::remove('plugins');
        }
        PluginManager::bootFromConfig();
    }

    public function testSetUpBootsConfiguredPluginsAfterAPluginManagerReset(): void
    {
        $this->assertTrue(PluginManager::isBooted());
        $this->assertSame(1, HttpTestCaseFixturePlugin::$registrations);
        $this->assertArrayHasKey(HttpTestCasePluginMarkerMiddleware::class, MiddlewareCatalog::getRegistered());
    }

    public function testRebootedPluginMiddlewareRunsInTheDispatchedPipeline(): void
    {
        $this->get('/plugin-boot-probe')
            ->assertNoContent()
            ->assertHeader('X-Plugin-Marker', 'booted');
    }

    public function testPluginMiddlewareSurvivesACatalogResetInTheTest(): void
    {
        MiddlewareCatalog::reset();

        $this->get('/plugin-boot-probe')->assertHeader('X-Plugin-Marker', 'booted');
    }

    public function testSetUpLeavesAlreadyBootedPluginsAlone(): void
    {
        $this->assertSame(1, HttpTestCaseFixturePlugin::$registrations);
    }
}

#[PluginAttribute(name: 'http-test-case-fixture')]
final class HttpTestCaseFixturePlugin implements PluginInterface
{
    public static int $registrations = 0;

    public function register(PluginRegistrar $r): void
    {
        self::$registrations++;
        $r->middleware(
            HttpTestCasePluginMarkerMiddleware::class,
            static fn(): MiddlewareInterface => new HttpTestCasePluginMarkerMiddleware(),
            before: RoutingMiddleware::class,
        );
    }
}

/** Answers every request itself, so the probe needs no route in the sandbox app. */
final class HttpTestCasePluginMarkerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new Response(204, ['X-Plugin-Marker' => 'booted']);
    }
}
