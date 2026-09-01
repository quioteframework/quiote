<?php

use Quiote\Config\Config;
use Quiote\Console\Application;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginConfigRegistry;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginManager;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[PluginAttribute(name: 'test/plugins-list-fixture')]
final class PluginsListFixturePlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
    }
}

/**
 * Exercises `plugins:list` through the CLI harness (CommandTester). The
 * sandbox app declares no `plugins` config entries of its own, so the "empty"
 * case is the real, unmodified state; a fixture plugin is registered directly
 * via {@see PluginManager::add()} to prove the populated case.
 */
final class PluginsListCommandTest extends PhpUnitTestCase
{
    protected function tearDown(): void
    {
        PluginManager::reset();
        PluginConfigRegistry::reset();
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        return new CommandTester($application->find('plugins:list'));
    }

    public function testReportsWhenNoPluginsAreRegistered(): void
    {
        PluginManager::reset();

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No plugins registered', $tester->getDisplay());
    }

    public function testListsARegisteredPluginByItsResolvedNameAndClass(): void
    {
        PluginManager::reset();
        PluginManager::add(new PluginsListFixturePlugin());

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('test/plugins-list-fixture', $display);
        $this->assertStringContainsString(PluginsListFixturePlugin::class, $display);
    }

    public function testJsonOutputContainsNameAndClass(): void
    {
        PluginManager::reset();
        PluginManager::add(new PluginsListFixturePlugin());

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $this->assertArrayHasKey('plugins', $payload);
        self::assertIsArray($payload['plugins']);
        $names = array_column($payload['plugins'], 'name');
        $classes = array_column($payload['plugins'], 'class');
        $this->assertContains('test/plugins-list-fixture', $names);
        $this->assertContains(PluginsListFixturePlugin::class, $classes);
    }

    public function testAProgrammaticallyAddedPluginIsSourcedAsCode(): void
    {
        PluginManager::reset();
        PluginConfigRegistry::reset();
        PluginManager::add(new PluginsListFixturePlugin());

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['plugins']);
        $bySourceClass = array_column($payload['plugins'], 'source', 'class');
        $this->assertSame('Code', $bySourceClass[PluginsListFixturePlugin::class]);
    }

    public function testAnAppDeclaredPluginIsSourcedAsGlobal(): void
    {
        PluginManager::reset();
        PluginConfigRegistry::reset();
        PluginConfigRegistry::contribute([PluginsListFixturePlugin::class], '/app/Config/plugins.xml');
        PluginManager::add(new PluginsListFixturePlugin());

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['plugins']);
        $bySourceClass = array_column($payload['plugins'], 'source', 'class');
        $this->assertSame('Global (xml)', $bySourceClass[PluginsListFixturePlugin::class]);
    }

    public function testAModuleDeclaredPluginIsSourcedByModuleName(): void
    {
        PluginManager::reset();
        PluginConfigRegistry::reset();
        $moduleDir = Config::getString('core.module_dir');
        PluginConfigRegistry::contribute(
            [PluginsListFixturePlugin::class],
            $moduleDir . '/Foo/Config/plugins.php'
        );
        PluginManager::add(new PluginsListFixturePlugin());

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['plugins']);
        $bySourceClass = array_column($payload['plugins'], 'source', 'class');
        $this->assertSame('Module Foo (php)', $bySourceClass[PluginsListFixturePlugin::class]);
    }

    public function testTableOutputShowsTheSourceColumn(): void
    {
        PluginManager::reset();
        PluginConfigRegistry::reset();
        PluginManager::add(new PluginsListFixturePlugin());

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringContainsString('Code', $tester->getDisplay());
    }
}
