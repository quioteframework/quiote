<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Event\Events;
use Quiote\Http\Client\HttpClientFactory;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\NamedPlugin;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginManager;
use Quiote\Plugin\PluginRegistrar;

/**
 * The plugin lifecycle: registration (programmatic + via the `plugins` config
 * key), each contribution kind routing to its seam, override/ordering rules,
 * dedup, and idempotency.
 */
class PluginManagerTest extends TestCase
{
    #[Before]
    #[After]
    public function resetAll(): void
    {
        PluginManager::reset();
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
        Events::reset();
        Config::remove('plugins');
        Config::remove('demo.setting');
        Config::remove('demo.appwins');
    }

    public function testProgrammaticallyAddedPluginIsRegisteredOnBoot(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $this->assertArrayHasKey(RecordingPlugin::class, PluginManager::registeredPlugins());
        $this->assertTrue(PluginManager::isBooted());
    }

    public function testPluginsConfigKeyDrivesRegistration(): void
    {
        Config::set('plugins', [RecordingPlugin::class], true);
        PluginManager::bootFromConfig();

        $this->assertArrayHasKey(RecordingPlugin::class, PluginManager::registeredPlugins());
    }

    public function testConfigDefaultIsSetIfAbsent(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('from-plugin', Config::getString('demo.setting'));
    }

    public function testAppConfigWinsOverPluginDefault(): void
    {
        Config::set('demo.appwins', 'app-value', true);
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        // RecordingPlugin tries to set demo.appwins => 'plugin-value' as a default.
        $this->assertSame('app-value', Config::getString('demo.appwins'));
    }

    public function testFirstPluginWinsForSameConfigDefault(): void
    {
        PluginManager::add(new RecordingPlugin());          // sets demo.setting => 'from-plugin'
        PluginManager::add(new SecondConfigPlugin());       // tries demo.setting => 'from-second'
        PluginManager::bootFromConfig();

        $this->assertSame('from-plugin', Config::getString('demo.setting'));
    }

    public function testMiddlewareContributionReachesCatalog(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $this->assertArrayHasKey('Demo\\PluginMiddleware', MiddlewareCatalog::getRegistered());
    }

    public function testAttributedMiddlewareContributionReachesCatalog(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $this->assertContains('Demo\\AttributedMiddleware', MiddlewareCatalog::getAttributedCandidates());
    }

    public function testListenerContributionReachesEvents(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(Events::hasListeners(DemoPluginEvent::class));
    }

    public function testModuleDirectoryContributionIsRecorded(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $this->assertContains('/tmp/demo-plugin/Modules', PluginManager::moduleDirectories());
    }

    public function testCommandContributionIsRecorded(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $this->assertContains('Demo\\PluginCommand', PluginManager::contributedCommands());
    }

    public function testServiceContributionIsAppliedToContainerIfAbsent(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->assertTrue($container->has('demo.service'));
        $this->assertInstanceOf(\stdClass::class, $container->get('demo.service'));
        $this->assertSame($container->get('demo.service'), $container->get('demo.alias'));
    }

    public function testServiceContributionDoesNotOverrideExistingBinding(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $appOwned = new \stdClass();
        $container->set('demo.service', $appOwned);
        PluginManager::configureContainer($container);

        $this->assertSame($appOwned, $container->get('demo.service'));
    }

    public function testHttpClientContributionIsAppliedToFactory(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::bootFromConfig();

        $factory = new HttpClientFactory();
        PluginManager::configureHttpClients($factory);

        $this->assertTrue($factory->has('demo-api'));
    }

    public function testBootIsIdempotent(): void
    {
        $plugin = new CountingPlugin();
        PluginManager::add($plugin);
        PluginManager::bootFromConfig();
        PluginManager::bootFromConfig();

        $this->assertSame(1, $plugin->registerCalls);
    }

    public function testDuplicatePluginClassRegisteredOnce(): void
    {
        PluginManager::add(new RecordingPlugin());
        PluginManager::add(new RecordingPlugin());
        Config::set('plugins', [RecordingPlugin::class], true);
        PluginManager::bootFromConfig();

        $this->assertCount(1, PluginManager::registeredPlugins());
    }

    public function testNonPluginClassInConfigIsIgnored(): void
    {
        Config::set('plugins', [\stdClass::class], true);
        PluginManager::bootFromConfig();

        $this->assertCount(0, PluginManager::registeredPlugins());
    }

    public function testClassStringActivationRefusesAPluginInterfaceClassWithoutTheAttribute(): void
    {
        // A real PluginInterface implementation, but with no #[Plugin] attribute:
        // naming it in a class-string source (plugins.* or an add() string) must
        // not be enough to activate it -- that's the whole point of the attribute
        // gate (see Quiote\Plugin\Attribute\Plugin's docblock).
        Config::set('plugins', [UnattributedPlugin::class], true);
        PluginManager::bootFromConfig();

        $this->assertCount(0, PluginManager::registeredPlugins());
    }

    public function testObjectInstanceActivationBypassesTheAttributeGate(): void
    {
        // The caller already wrote `new UnattributedPlugin()` themselves --
        // that's the trust boundary, so the attribute isn't required here.
        PluginManager::add(new UnattributedPlugin());
        PluginManager::bootFromConfig();

        $this->assertArrayHasKey(UnattributedPlugin::class, PluginManager::registeredPlugins());
    }

    public function testNamedPluginNameTakesPrecedenceOverAttributeName(): void
    {
        PluginManager::add(new NamedOverAttributePlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('from-named-plugin', NamedOverAttributePlugin::$capturedPluginName);
    }

    public function testPluginWithNoResolvableNameThrows(): void
    {
        $this->expectException(\Quiote\Exception\QuioteException::class);
        $this->expectExceptionMessage(UnnamablePlugin::class);

        PluginManager::add(new UnnamablePlugin());
        PluginManager::bootFromConfig();
    }
}

#[PluginAttribute(name: 'recording')]
final class RecordingPlugin implements PluginInterface
{
    public function register(PluginRegistrar $r): void
    {
        $r->configDefault('demo.setting', 'from-plugin')
            ->configDefault('demo.appwins', 'plugin-value')
            ->service('demo.service', fn() => new \stdClass(), Container::SCOPE_SINGLETON, 'demo.alias')
            ->middleware('Demo\\PluginMiddleware', fn() => new \stdClass())
            ->attributedMiddleware('Demo\\AttributedMiddleware')
            ->listen(DemoPluginEvent::class, fn() => null)
            ->moduleDirectory('/tmp/demo-plugin/Modules')
            ->command('Demo\\PluginCommand')
            ->httpClient('demo-api', fn($c) => $c->baseUri('https://demo.example'));
    }
}

#[PluginAttribute(name: 'second')]
final class SecondConfigPlugin implements PluginInterface
{
    public function register(PluginRegistrar $r): void
    {
        $r->configDefault('demo.setting', 'from-second');
    }
}

#[PluginAttribute(name: 'counting')]
final class CountingPlugin implements PluginInterface
{
    public int $registerCalls = 0;

    public function register(PluginRegistrar $r): void
    {
        $this->registerCalls++;
    }
}

final class DemoPluginEvent extends \Quiote\Event\Event
{
}

// Deliberately NOT #[PluginAttribute] -- see testClassStringActivationRefusesAPluginInterfaceClassWithoutTheAttribute().
// Implements NamedPlugin instead of relying on the attribute for its name.
final class UnattributedPlugin implements NamedPlugin
{
    public function name(): string
    {
        return 'unattributed';
    }

    public function register(PluginRegistrar $r): void
    {
    }
}

// Carries both an attribute name and a NamedPlugin::name() -- PluginManager
// must prefer the latter (see testNamedPluginNameTakesPrecedenceOverAttributeName).
#[PluginAttribute(name: 'from-attribute')]
final class NamedOverAttributePlugin implements NamedPlugin
{
    public static string $capturedPluginName = '';

    public function name(): string
    {
        return 'from-named-plugin';
    }

    public function register(PluginRegistrar $r): void
    {
        self::$capturedPluginName = $r->pluginName();
    }
}

// Neither NamedPlugin nor an attribute `name` argument -- see testPluginWithNoResolvableNameThrows().
#[PluginAttribute]
final class UnnamablePlugin implements PluginInterface
{
    public function register(PluginRegistrar $r): void
    {
    }
}
