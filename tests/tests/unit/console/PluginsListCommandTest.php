<?php

use Quiote\Console\Application;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
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
}
