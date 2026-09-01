<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Console\Application;
use Quiote\Plugin\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(name: 'plugin:contributed', description: 'Contributed by a plugin for testing.')]
final class ContributedTestCommand extends Command
{
}

/** Same configured name as the built-in `about`, to exercise the dedupe guard. */
#[AsCommand(name: 'about', description: 'Impostor.')]
final class ImpostorAboutCommand extends Command
{
}

/** No #[AsCommand], so dedupe falls back to matching on the class name. */
final class AttributelessTestCommand extends Command
{
    public function __construct()
    {
        parent::__construct('plugin:attributeless');
    }
}

final class NotACommand
{
}

/**
 * The `quiote` CLI's command wiring: which commands ship in the box, and how
 * plugin-contributed commands are folded in (once each, skipping anything
 * unusable) by addContributedCommands().
 */
final class ApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        PluginManager::reset();
    }

    protected function tearDown(): void
    {
        PluginManager::reset();
    }

    // ---------------------------------------------------------------
    // Built-in commands.
    // ---------------------------------------------------------------

    /** @return list<array{string}> */
    public static function builtInCommandNames(): array
    {
        return [
            ['new'],
            ['about'],
            ['routes:list'],
            ['routes:compile'],
            ['plugins:list'],
            ['middleware:list'],
            ['openapi:generate'],
            ['cache:warmup'],
            ['make:action'],
            ['make:module'],
            ['make:middleware'],
            ['make:job'],
            ['serve'],
        ];
    }

    #[DataProvider('builtInCommandNames')]
    public function testShipsWithTheDocumentedCommands(string $name): void
    {
        $this->assertTrue((new Application())->has($name), $name . ' should be registered');
    }

    public function testApplicationIsNamedAndVersionedFromConfig(): void
    {
        $application = new Application();

        $this->assertSame('quiote', $application->getName());
        $this->assertSame(Config::getString('quiote.version', 'dev'), $application->getVersion());
    }

    // ---------------------------------------------------------------
    // Plugin-contributed commands.
    // ---------------------------------------------------------------

    public function testContributedCommandsAreRegisteredOnConstruction(): void
    {
        PluginManager::addCommand(ContributedTestCommand::class);

        $application = new Application();

        $this->assertTrue($application->has('plugin:contributed'));
        $this->assertInstanceOf(ContributedTestCommand::class, $application->find('plugin:contributed'));
    }

    public function testContributedCommandsCanBeAddedAfterABootstrapPopulatesTheRegistry(): void
    {
        // bin/quiote builds the Application before any bootstrap runs, so this
        // second call is how a late-registered plugin command still shows up.
        $application = new Application();
        $this->assertFalse($application->has('plugin:contributed'));

        PluginManager::addCommand(ContributedTestCommand::class);
        $application->addContributedCommands();

        $this->assertTrue($application->has('plugin:contributed'));
    }

    public function testAddContributedCommandsIsIdempotent(): void
    {
        PluginManager::addCommand(ContributedTestCommand::class);
        $application = new Application();
        $before = $application->find('plugin:contributed');

        $application->addContributedCommands();
        $application->addContributedCommands();

        // Re-registering would replace the instance; the dedupe guard keeps it.
        $this->assertSame($before, $application->find('plugin:contributed'));
    }

    public function testAContributedCommandNeverDisplacesABuiltInOfTheSameName(): void
    {
        PluginManager::addCommand(ImpostorAboutCommand::class);

        $application = new Application();

        $this->assertInstanceOf(\Quiote\Console\Command\AboutCommand::class, $application->find('about'));
    }

    public function testAContributedCommandWithoutAnAsCommandAttributeStillRegisters(): void
    {
        PluginManager::addCommand(AttributelessTestCommand::class);

        $application = new Application();

        $this->assertTrue($application->has('plugin:attributeless'));
    }

    public function testUnknownContributedClassesAreSkipped(): void
    {
        PluginManager::addCommand('Definitely\\Not\\A\\Real\\CommandClass');
        PluginManager::addCommand(ContributedTestCommand::class);

        $application = new Application();

        // The missing class must not abort registration of the ones that follow.
        $this->assertTrue($application->has('plugin:contributed'));
    }

    public function testContributedClassesThatAreNotCommandsAreSkipped(): void
    {
        PluginManager::addCommand(NotACommand::class);
        PluginManager::addCommand(ContributedTestCommand::class);

        $application = new Application();

        $this->assertTrue($application->has('plugin:contributed'));
        $this->assertNotContains(NotACommand::class, array_map(
            static fn(Command $command): string => $command::class,
            $application->all()
        ));
    }
}
