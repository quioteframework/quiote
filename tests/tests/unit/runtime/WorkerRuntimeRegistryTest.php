<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Runtime\Worker\FrankenPhpRuntime;
use Quiote\Runtime\Worker\SapiRuntime;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeCapabilities;
use Quiote\Runtime\Worker\WorkerRuntimeInterface;
use Quiote\Runtime\Worker\WorkerRuntimeRegistry;

/** A runtime that always claims the process, at a priority the test controls. */
final class RegistryTestSupportedRuntime implements WorkerRuntimeInterface
{
    public static function isSupported(): bool
    {
        return true;
    }

    public static function alias(): string
    {
        return 'registry-supported';
    }

    public static function detectionPriority(): int
    {
        return 500;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: true);
    }

    public function run(WorkerLoop $loop): void
    {
    }
}

/** Same priority as the one above, to pin down the tie-break. */
final class RegistryTestTieRuntime implements WorkerRuntimeInterface
{
    public static function isSupported(): bool
    {
        return true;
    }

    public static function alias(): string
    {
        return 'registry-tie';
    }

    public static function detectionPriority(): int
    {
        return 500;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: true);
    }

    public function run(WorkerLoop $loop): void
    {
    }
}

/** Never claims the process, so detection must skip it. */
final class RegistryTestUnsupportedRuntime implements WorkerRuntimeInterface
{
    public static function isSupported(): bool
    {
        return false;
    }

    public static function alias(): string
    {
        return 'registry-unsupported';
    }

    public static function detectionPriority(): int
    {
        return PHP_INT_MAX;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi();
    }

    public function run(WorkerLoop $loop): void
    {
    }
}

final class RegistryTestNotARuntime
{
}

final class WorkerRuntimeRegistryTest extends TestCase
{
    #[Before]
    #[After]
    public function resetRegistry(): void
    {
        WorkerRuntimeRegistry::reset();
    }

    /**
     * A class name that deliberately does not exist, standing in for an alias
     * whose package was uninstalled. Assembled at runtime rather than written as
     * a literal so it can be typed as a class-string without claiming the class
     * is loadable.
     *
     * @return class-string<WorkerRuntimeInterface>
     */
    private static function uninstalledRuntimeClass(): string
    {
        /** @var class-string<WorkerRuntimeInterface> $class */
        $class = implode('\\', ['Quiote', 'Runtime', 'Worker', 'ThisRuntimeWasNeverInstalled']);
        return $class;
    }

    public function testCoreAliasesAreRegisteredOutOfTheBox(): void
    {
        $this->assertTrue(WorkerRuntimeRegistry::has('sapi'));
        $this->assertTrue(WorkerRuntimeRegistry::has('frankenphp'));
        $this->assertSame(SapiRuntime::class, WorkerRuntimeRegistry::resolve('sapi'));
        $this->assertSame(FrankenPhpRuntime::class, WorkerRuntimeRegistry::resolve('frankenphp'));
    }

    public function testRegisterAddsAnAliasAndResetRemovesIt(): void
    {
        WorkerRuntimeRegistry::register('registry-supported', RegistryTestSupportedRuntime::class);

        $this->assertTrue(WorkerRuntimeRegistry::has('registry-supported'));
        $this->assertArrayHasKey('registry-supported', WorkerRuntimeRegistry::aliases());

        WorkerRuntimeRegistry::reset();

        $this->assertFalse(WorkerRuntimeRegistry::has('registry-supported'));
        $this->assertSame(['frankenphp', 'sapi'], array_keys(WorkerRuntimeRegistry::aliases()));
    }

    public function testResolvePassesAnUnknownStringThroughSoAClassNameStillWorks(): void
    {
        $this->assertSame(
            RegistryTestSupportedRuntime::class,
            WorkerRuntimeRegistry::resolve(RegistryTestSupportedRuntime::class),
        );
    }

    public function testInstantiateClassForAcceptsBothAnAliasAndAClassName(): void
    {
        WorkerRuntimeRegistry::register('registry-supported', RegistryTestSupportedRuntime::class);

        $this->assertSame(
            RegistryTestSupportedRuntime::class,
            WorkerRuntimeRegistry::instantiateClassFor('registry-supported'),
        );
        $this->assertSame(
            RegistryTestSupportedRuntime::class,
            WorkerRuntimeRegistry::instantiateClassFor(RegistryTestSupportedRuntime::class),
        );
    }

    public function testInstantiateClassForRejectsAnUnknownAliasWithAPointerToPlugins(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No runtime alias by that name is registered/');
        WorkerRuntimeRegistry::instantiateClassFor('nope');
    }

    public function testInstantiateClassForBlamesAMissingPackageWhenTheAliasIsRegistered(): void
    {
        $missing = self::uninstalledRuntimeClass();
        WorkerRuntimeRegistry::register('ghost', $missing);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is its package installed\?/');
        WorkerRuntimeRegistry::instantiateClassFor('ghost');
    }

    public function testInstantiateClassForRejectsAClassThatIsNotARuntime(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement/');
        WorkerRuntimeRegistry::instantiateClassFor(RegistryTestNotARuntime::class);
    }

    public function testDetectPrefersTheHighestPriorityRuntimeThatClaimsTheProcess(): void
    {
        WorkerRuntimeRegistry::register('registry-unsupported', RegistryTestUnsupportedRuntime::class);
        WorkerRuntimeRegistry::register('registry-supported', RegistryTestSupportedRuntime::class);

        // The unsupported one sits at PHP_INT_MAX, so choosing the other proves
        // isSupported() gates before priority is considered at all.
        $this->assertSame(RegistryTestSupportedRuntime::class, WorkerRuntimeRegistry::detect());
    }

    public function testDetectBreaksAPriorityTieOnRegistrationOrder(): void
    {
        WorkerRuntimeRegistry::register('registry-supported', RegistryTestSupportedRuntime::class);
        WorkerRuntimeRegistry::register('registry-tie', RegistryTestTieRuntime::class);

        $this->assertSame(RegistryTestSupportedRuntime::class, WorkerRuntimeRegistry::detect());

        WorkerRuntimeRegistry::reset();
        WorkerRuntimeRegistry::register('registry-tie', RegistryTestTieRuntime::class);
        WorkerRuntimeRegistry::register('registry-supported', RegistryTestSupportedRuntime::class);

        $this->assertSame(RegistryTestTieRuntime::class, WorkerRuntimeRegistry::detect());
    }

    public function testDetectFallsBackToSapiWhenNothingElseClaimsTheProcess(): void
    {
        // Under the CLI SAPI nothing else does, which is the whole point of
        // SapiRuntime sitting at PHP_INT_MIN: detection can never come back empty.
        $this->assertSame(SapiRuntime::class, WorkerRuntimeRegistry::detect());
    }

    public function testDetectIgnoresARegisteredAliasWhoseClassIsGone(): void
    {
        $missing = self::uninstalledRuntimeClass();
        WorkerRuntimeRegistry::register('ghost', $missing);

        $this->assertSame(SapiRuntime::class, WorkerRuntimeRegistry::detect());
    }
}
