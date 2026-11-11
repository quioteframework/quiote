<?php

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginManager;
use Quiote\Plugin\PluginRegistrar;

/**
 * Plugin middleware is registered once per process, so the catalog keeps it apart from what an
 * application or test registers: reset() clears only the latter, PluginManager::reset() clears
 * both, and snapshot()/restore() put the whole catalog back.
 */
final class MiddlewareCatalogPluginRegistrationTest extends TestCase
{
    #[Before]
    #[After]
    public function resetAll(): void
    {
        PluginManager::reset();
    }

    private static function factory(): callable
    {
        return static fn(): MiddlewareInterface => new CatalogPluginFixtureMiddleware();
    }

    public function testResetKeepsPluginMiddleware(): void
    {
        MiddlewareCatalog::registerFromPlugin('Plugin\\Positioned', self::factory(), after: 'Core\\Routing');
        MiddlewareCatalog::registerAttributedFromPlugin('Plugin\\Attributed');

        MiddlewareCatalog::reset();

        $this->assertArrayHasKey('Plugin\\Positioned', MiddlewareCatalog::getRegistered());
        $this->assertSame('Core\\Routing', MiddlewareCatalog::getRegistered()['Plugin\\Positioned']['after']);
        $this->assertSame(['Plugin\\Attributed'], MiddlewareCatalog::getAttributedCandidates());
    }

    public function testResetClearsWhatTheApplicationSetUp(): void
    {
        MiddlewareCatalog::initialize(['App\\Off' => false]);
        MiddlewareCatalog::register('App\\Positioned', self::factory());
        MiddlewareCatalog::registerAttributed('App\\Attributed');
        MiddlewareCatalog::replaceCoreStack(
            static fn(Context $context): array => [],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT,
        );

        MiddlewareCatalog::reset();

        $this->assertSame([], MiddlewareCatalog::all());
        $this->assertTrue(MiddlewareCatalog::isEnabled('App\\Off'));
        $this->assertSame([], MiddlewareCatalog::getRegistered());
        $this->assertSame([], MiddlewareCatalog::getAttributedCandidates());
        $this->assertFalse(MiddlewareCatalog::hasCoreStackOverride());
    }

    public function testResetPluginRegistrationsClearsOnlyPluginMiddleware(): void
    {
        MiddlewareCatalog::registerFromPlugin('Plugin\\Positioned', self::factory());
        MiddlewareCatalog::registerAttributedFromPlugin('Plugin\\Attributed');
        MiddlewareCatalog::register('App\\Positioned', self::factory());
        MiddlewareCatalog::registerAttributed('App\\Attributed');

        MiddlewareCatalog::resetPluginRegistrations();

        $this->assertSame(['App\\Positioned'], array_keys(MiddlewareCatalog::getRegistered()));
        $this->assertSame(['App\\Attributed'], MiddlewareCatalog::getAttributedCandidates());
    }

    public function testPluginRegistrarContributionsSurviveACatalogReset(): void
    {
        PluginManager::add(new CatalogFixturePlugin());
        PluginManager::bootFromConfig();

        MiddlewareCatalog::reset();

        $this->assertArrayHasKey('Fixture\\PluginPositioned', MiddlewareCatalog::getRegistered());
        $this->assertContains(CatalogPluginFixtureMiddleware::class, MiddlewareCatalog::getAttributedCandidates());
        $this->assertNotNull(MiddlewareCatalog::attributedFactory(CatalogPluginFixtureMiddleware::class));
    }

    public function testPluginManagerResetRemovesPluginMiddleware(): void
    {
        PluginManager::add(new CatalogFixturePlugin());
        PluginManager::bootFromConfig();

        PluginManager::reset();

        $this->assertSame([], MiddlewareCatalog::getRegistered());
        $this->assertSame([], MiddlewareCatalog::getAttributedCandidates());
        $this->assertNull(MiddlewareCatalog::attributedFactory(CatalogPluginFixtureMiddleware::class));
    }

    public function testApplicationRegistrationReplacesThePluginEntryInPlace(): void
    {
        MiddlewareCatalog::registerFromPlugin('Plugin\\First', self::factory());
        MiddlewareCatalog::registerFromPlugin('Shared', self::factory(), after: 'Plugin\\Position');
        MiddlewareCatalog::registerFromPlugin('Plugin\\Last', self::factory());
        MiddlewareCatalog::register('Shared', self::factory(), before: 'App\\Position', priority: 5);

        $registered = MiddlewareCatalog::getRegistered();

        $this->assertSame(['Plugin\\First', 'Shared', 'Plugin\\Last'], array_keys($registered));
        $this->assertNull($registered['Shared']['after']);
        $this->assertSame('App\\Position', $registered['Shared']['before']);
        $this->assertSame(5, $registered['Shared']['priority']);
    }

    public function testApplicationAttributedEntryReplacesThePluginFactory(): void
    {
        MiddlewareCatalog::registerAttributedFromPlugin('Shared', self::factory());
        $this->assertNotNull(MiddlewareCatalog::attributedFactory('Shared'));

        MiddlewareCatalog::registerAttributed('Shared');

        $this->assertSame(['Shared'], MiddlewareCatalog::getAttributedCandidates());
        $this->assertNull(MiddlewareCatalog::attributedFactory('Shared'));
    }

    public function testAttributedFactoryIsNullForAnUnknownClass(): void
    {
        $this->assertNull(MiddlewareCatalog::attributedFactory('Nobody\\Registered'));
    }

    public function testRestorePutsTheWholeCatalogBack(): void
    {
        MiddlewareCatalog::initialize(['App\\Off' => false]);
        MiddlewareCatalog::register('App\\Positioned', self::factory());
        MiddlewareCatalog::registerAttributed('App\\Attributed');
        MiddlewareCatalog::registerFromPlugin('Plugin\\Positioned', self::factory());
        MiddlewareCatalog::registerAttributedFromPlugin('Plugin\\Attributed');
        $stack = [new CatalogPluginFixtureMiddleware()];
        MiddlewareCatalog::replaceCoreStack(
            static fn(Context $context): array => $stack,
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT,
        );
        $snapshot = MiddlewareCatalog::snapshot();

        PluginManager::reset();
        $this->assertSame([], MiddlewareCatalog::getRegistered());

        MiddlewareCatalog::restore($snapshot);

        $this->assertSame(['App\\Off' => false], MiddlewareCatalog::all());
        $this->assertSame(['Plugin\\Positioned', 'App\\Positioned'], array_keys(MiddlewareCatalog::getRegistered()));
        $this->assertSame(['Plugin\\Attributed', 'App\\Attributed'], MiddlewareCatalog::getAttributedCandidates());
        $this->assertTrue(MiddlewareCatalog::hasCoreStackOverride());
        $this->assertSame($stack, MiddlewareCatalog::buildCoreStack(Context::getInstance()));
    }

    public function testRestoreDiscardsWhatWasRegisteredAfterTheSnapshot(): void
    {
        $snapshot = MiddlewareCatalog::snapshot();

        MiddlewareCatalog::registerFromPlugin('Plugin\\Late', self::factory());
        MiddlewareCatalog::registerAttributedFromPlugin('Plugin\\LateAttributed');
        MiddlewareCatalog::register('App\\Late', self::factory());
        MiddlewareCatalog::replaceCoreStack(
            static fn(Context $context): array => [],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT,
        );

        MiddlewareCatalog::restore($snapshot);

        $this->assertSame([], MiddlewareCatalog::getRegistered());
        $this->assertSame([], MiddlewareCatalog::getAttributedCandidates());
        $this->assertFalse(MiddlewareCatalog::hasCoreStackOverride());
        $this->expectException(QuioteException::class);
        MiddlewareCatalog::buildCoreStack(Context::getInstance());
    }
}

#[PluginAttribute(name: 'catalog-fixture')]
final class CatalogFixturePlugin implements PluginInterface
{
    public function register(PluginRegistrar $r): void
    {
        $r->middleware('Fixture\\PluginPositioned', static fn(): MiddlewareInterface => new CatalogPluginFixtureMiddleware())
            ->attributedMiddleware(
                CatalogPluginFixtureMiddleware::class,
                static fn(Context $context): MiddlewareInterface => new CatalogPluginFixtureMiddleware(),
            );
    }
}

final class CatalogPluginFixtureMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new Response(204);
    }
}
