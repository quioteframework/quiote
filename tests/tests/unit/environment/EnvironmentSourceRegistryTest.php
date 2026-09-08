<?php

use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Environment\EnvironmentSourceInterface;
use Quiote\Environment\EnvironmentSourceRegistry;

final class EnvironmentSourceRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        EnvironmentSourceRegistry::reset();
    }

    public function testResolveIsEmptyWithNothingRegistered(): void
    {
        $this->assertSame([], EnvironmentSourceRegistry::resolve(new Container()));
    }

    public function testResolveReturnsEveryFactoryThatDoesNotDecline(): void
    {
        $source = new class implements EnvironmentSourceInterface {
            public function label(): string
            {
                return 'fixture';
            }

            public function listEnvironments(): array
            {
                return ['staging', 'production'];
            }
        };

        EnvironmentSourceRegistry::register('fixture', static fn(Container $c): EnvironmentSourceInterface => $source);
        $resolved = EnvironmentSourceRegistry::resolve(new Container());

        $this->assertSame(['fixture' => $source], $resolved);
    }

    public function testAFactoryReturningNullIsOmittedRatherThanErroring(): void
    {
        EnvironmentSourceRegistry::register('declines', static fn(Container $c): ?EnvironmentSourceInterface => null);

        $this->assertSame([], EnvironmentSourceRegistry::resolve(new Container()));
    }

    public function testRegisteringTheSameIdTwiceReplacesTheFactory(): void
    {
        $first = new class implements EnvironmentSourceInterface {
            public function label(): string
            {
                return 'first';
            }

            public function listEnvironments(): array
            {
                return [];
            }
        };
        $second = new class implements EnvironmentSourceInterface {
            public function label(): string
            {
                return 'second';
            }

            public function listEnvironments(): array
            {
                return [];
            }
        };

        EnvironmentSourceRegistry::register('same-id', static fn(Container $c): EnvironmentSourceInterface => $first);
        EnvironmentSourceRegistry::register('same-id', static fn(Container $c): EnvironmentSourceInterface => $second);

        $resolved = EnvironmentSourceRegistry::resolve(new Container());
        $this->assertSame(['same-id' => $second], $resolved);
    }

    public function testResetClearsEveryContributedFactory(): void
    {
        EnvironmentSourceRegistry::register('fixture', static fn(Container $c): ?EnvironmentSourceInterface => null);
        EnvironmentSourceRegistry::reset();

        $this->assertSame([], EnvironmentSourceRegistry::resolve(new Container()));
    }
}
