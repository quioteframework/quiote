<?php

use Quiote\Console\Application;
use Quiote\DI\Container;
use Quiote\Environment\EnvironmentSourceInterface;
use Quiote\Environment\EnvironmentSourceRegistry;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercises `env:list` through the CLI harness. {@see EnvironmentSourceRegistry}
 * is process-global, so every test registers its own fixture source(s) and
 * relies on tearDown() resetting the registry -- mirroring
 * `PluginsListCommandTest`'s relationship to `PluginManager`.
 */
final class EnvironmentListCommandTest extends PhpUnitTestCase
{
    protected function tearDown(): void
    {
        EnvironmentSourceRegistry::reset();
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        return new CommandTester($application->find('env:list'));
    }

    public function testReportsWhenNoSourcesAreRegistered(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No environment sources registered', $tester->getDisplay());
    }

    public function testListsEnvironmentsFromARegisteredSource(): void
    {
        EnvironmentSourceRegistry::register('fixture', static fn(Container $c): EnvironmentSourceInterface => new EnvironmentListCommandTestFixtureSource(
            'fixture source',
            ['staging', 'development'],
        ));

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('development', $display);
        $this->assertStringContainsString('staging', $display);
        $this->assertStringContainsString('fixture source', $display);
    }

    public function testMergesAndSortsEnvironmentsFromMultipleSources(): void
    {
        EnvironmentSourceRegistry::register('a', static fn(Container $c): EnvironmentSourceInterface => new EnvironmentListCommandTestFixtureSource('source a', ['staging']));
        EnvironmentSourceRegistry::register('b', static fn(Container $c): EnvironmentSourceInterface => new EnvironmentListCommandTestFixtureSource('source b', ['staging', 'production']));

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $this->assertSame(['production', 'staging'], $payload['environments']);
    }

    public function testASourceThatThrowsIsReportedAsAWarningRatherThanFailingTheCommand(): void
    {
        EnvironmentSourceRegistry::register('broken', static fn(Container $c): EnvironmentSourceInterface => new EnvironmentListCommandTestThrowingSource());
        EnvironmentSourceRegistry::register('ok', static fn(Container $c): EnvironmentSourceInterface => new EnvironmentListCommandTestFixtureSource('ok source', ['production']));

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('unreachable in tests', $display);
        $this->assertStringContainsString('production', $display);
    }

    public function testJsonOutputContainsEnvironmentsBySourceAndDiagnostics(): void
    {
        EnvironmentSourceRegistry::register('fixture', static fn(Container $c): EnvironmentSourceInterface => new EnvironmentListCommandTestFixtureSource('fixture source', ['staging']));

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $this->assertSame(['staging'], $payload['environments']);
        $this->assertSame(['fixture source' => ['staging']], $payload['by_source']);
        $this->assertSame([], $payload['diagnostics']);
    }

    public function testReportsWhenSourcesAreRegisteredButNoneHaveAnyEnvironments(): void
    {
        EnvironmentSourceRegistry::register('empty', static fn(Container $c): EnvironmentSourceInterface => new EnvironmentListCommandTestFixtureSource('empty source', []));

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No environments found', $tester->getDisplay());
    }

    public function testAllSourcesFailingStillExitsSuccessfullyWithOnlyWarnings(): void
    {
        // A broken environment source degrades the output, not the exit code -- the same
        // per-item-soft-failure convention CassetteListCommand's CASSETTE_UNREADABLE diagnostic
        // follows for an unreadable cassette.
        EnvironmentSourceRegistry::register('broken', static fn(Container $c): EnvironmentSourceInterface => new EnvironmentListCommandTestThrowingSource());

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('unreachable in tests', $display);
        $this->assertStringContainsString('No environments found', $display);
    }
}

final class EnvironmentListCommandTestFixtureSource implements EnvironmentSourceInterface
{
    /** @param list<string> $environments */
    public function __construct(private readonly string $label, private readonly array $environments)
    {
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return list<string> */
    public function listEnvironments(): array
    {
        return $this->environments;
    }
}

final class EnvironmentListCommandTestThrowingSource implements EnvironmentSourceInterface
{
    public function label(): string
    {
        return 'broken source';
    }

    /** @return list<string> */
    public function listEnvironments(): array
    {
        throw new \RuntimeException('unreachable in tests');
    }
}
