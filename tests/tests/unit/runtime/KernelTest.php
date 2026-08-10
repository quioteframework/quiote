<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Runtime\Kernel;
use Quiote\Runtime\Worker\SapiRuntime;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeCapabilities;
use Quiote\Runtime\Worker\WorkerRuntimeInterface;
use Quiote\Runtime\Worker\WorkerRuntimeRegistry;

/** A runtime that claims every process, so an explicit request for it resolves. */
final class KernelTestSupportedRuntime implements WorkerRuntimeInterface
{
    public static function isSupported(): bool
    {
        return true;
    }

    public static function alias(): string
    {
        return 'kernel-supported';
    }

    public static function detectionPriority(): int
    {
        return 900;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: true);
    }

    public function run(WorkerLoop $loop): void
    {
    }
}

/** A runtime that never claims the process, standing in for "not started by that server". */
final class KernelTestUnsupportedRuntime implements WorkerRuntimeInterface
{
    public static function isSupported(): bool
    {
        return false;
    }

    public static function alias(): string
    {
        return 'roadrunner';
    }

    public static function detectionPriority(): int
    {
        return 800;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: true);
    }

    public function run(WorkerLoop $loop): void
    {
    }
}

/**
 * Kernel's own remit is the decisions around the request loop: reading the
 * create() options, and resolving which worker runtime hosts this process.
 * Both are configuration-shaped, and getting either wrong is the difference
 * between a persistent RoadRunner deployment and silently serving one request
 * per process.
 *
 * run() itself boots the framework and hands off to that runtime; it is covered
 * by the worker integration tests rather than here, since it mutates
 * process-wide state (config, contexts, WorkerManager) that the rest of this
 * suite shares.
 */
final class KernelTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    #[Before]
    public function captureEnvironment(): void
    {
        foreach (['QUIOTE_ENV', 'QUIOTE_CONTEXT', 'QUIOTE_WORKER_RUNTIME', 'QUIOTE_MAX_REQUESTS'] as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
        }
        WorkerRuntimeRegistry::reset();
    }

    #[After]
    public function restoreEnvironment(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if (is_string($value)) {
                putenv($name . '=' . $value);
            } else {
                putenv($name);
            }
        }
        WorkerRuntimeRegistry::reset();
        Config::remove('core.worker_runtime');
        Config::remove('core.worker.max_requests');
        Config::remove('core.worker.cleanup_interval');
    }

    private function read(Kernel $kernel, string $property): mixed
    {
        return (new ReflectionProperty(Kernel::class, $property))->getValue($kernel);
    }

    private function call(Kernel $kernel, string $method): mixed
    {
        return (new ReflectionMethod(Kernel::class, $method))->invoke($kernel);
    }

    // --- create() options --------------------------------------------------

    public function testCreateDefaultsToTheProdEnvironmentAndWebContext(): void
    {
        $kernel = Kernel::create();

        $this->assertSame('prod', $this->read($kernel, 'env'));
        $this->assertSame('web', $this->read($kernel, 'contextName'));
    }

    public function testTheEnvironmentAndContextComeFromTheEnvironmentWhenUnset(): void
    {
        putenv('QUIOTE_ENV=staging');
        putenv('QUIOTE_CONTEXT=console');

        $kernel = Kernel::create();

        $this->assertSame('staging', $this->read($kernel, 'env'));
        $this->assertSame('console', $this->read($kernel, 'contextName'));
    }

    public function testExplicitOptionsWinOverTheEnvironment(): void
    {
        putenv('QUIOTE_ENV=staging');
        putenv('QUIOTE_CONTEXT=console');

        $kernel = Kernel::create(['env' => 'testing', 'context' => 'soap']);

        $this->assertSame('testing', $this->read($kernel, 'env'));
        $this->assertSame('soap', $this->read($kernel, 'contextName'));
    }

    /**
     * getenv() answers false for an unset variable and the options array is
     * untyped, so anything that is not a string has to fall back rather than
     * reach the Context as a bool.
     */
    public function testANonStringEnvironmentOrContextFallsBackToTheDefault(): void
    {
        $kernel = Kernel::create(['env' => ['array'], 'context' => 42]);

        $this->assertSame('prod', $this->read($kernel, 'env'));
        $this->assertSame('web', $this->read($kernel, 'contextName'));
    }

    public function testTheApplicationDirectoryIsRecordedWhenGiven(): void
    {
        $kernel = Kernel::create(['app_dir' => '/srv/app']);

        $this->assertSame('/srv/app', $this->read($kernel, 'appDir'));
    }

    public function testANonStringApplicationDirectoryIsIgnored(): void
    {
        $kernel = Kernel::create(['app_dir' => 42]);

        $this->assertNull($this->read($kernel, 'appDir'));
    }

    public function testPrewarmIsOffUnlessAskedFor(): void
    {
        $this->assertFalse($this->read(Kernel::create(), 'prewarm'));
        $this->assertTrue($this->read(Kernel::create(['prewarm' => true]), 'prewarm'));
        $this->assertTrue($this->read(Kernel::create(['prewarm' => 1]), 'prewarm'));
        $this->assertFalse($this->read(Kernel::create(['prewarm' => 0]), 'prewarm'));
    }

    public function testExtraContextsAreRecorded(): void
    {
        $kernel = Kernel::create(['contexts' => ['console', 'soap']]);

        $this->assertSame(['console', 'soap'], $this->read($kernel, 'extraContexts'));
    }

    /** A context name is used to build a Context, so a non-string cannot be carried through. */
    public function testNonStringExtraContextsAreDroppedAndTheListStaysAList(): void
    {
        $kernel = Kernel::create(['contexts' => ['console', 42, null, 'soap']]);

        $this->assertSame(['console', 'soap'], $this->read($kernel, 'extraContexts'));
    }

    public function testANonArrayContextsOptionIsIgnored(): void
    {
        $this->assertSame([], $this->read(Kernel::create(['contexts' => 'console']), 'extraContexts'));
    }

    public function testTheRuntimeOverrideAcceptsAnAliasOrAnInstance(): void
    {
        $this->assertSame('roadrunner', $this->read(Kernel::create(['worker_runtime' => 'roadrunner']), 'runtimeOverride'));

        $instance = new KernelTestSupportedRuntime();
        $this->assertSame($instance, $this->read(Kernel::create(['worker_runtime' => $instance]), 'runtimeOverride'));
    }

    public function testAnEmptyRuntimeOverrideIsNoOverrideAtAll(): void
    {
        $this->assertNull($this->read(Kernel::create(['worker_runtime' => '']), 'runtimeOverride'));
        $this->assertNull($this->read(Kernel::create(['worker_runtime' => null]), 'runtimeOverride'));
    }

    // --- runtime resolution ------------------------------------------------

    public function testAnInstanceOverrideIsUsedAsIs(): void
    {
        $instance = new KernelTestSupportedRuntime();

        $this->assertSame($instance, $this->call(Kernel::create(['worker_runtime' => $instance]), 'selectRuntime'));
    }

    public function testAnAliasOverrideIsInstantiated(): void
    {
        WorkerRuntimeRegistry::register('kernel-supported', KernelTestSupportedRuntime::class);

        $runtime = $this->call(Kernel::create(['worker_runtime' => 'kernel-supported']), 'selectRuntime');

        $this->assertInstanceOf(KernelTestSupportedRuntime::class, $runtime);
    }

    public function testTheRuntimeMayBeNamedByFullyQualifiedClassName(): void
    {
        $runtime = $this->call(Kernel::create(['worker_runtime' => KernelTestSupportedRuntime::class]), 'selectRuntime');

        $this->assertInstanceOf(KernelTestSupportedRuntime::class, $runtime);
    }

    public function testTheEnvironmentVariableSelectsTheRuntimeWhenNoOptionWasGiven(): void
    {
        WorkerRuntimeRegistry::register('kernel-supported', KernelTestSupportedRuntime::class);
        putenv('QUIOTE_WORKER_RUNTIME=kernel-supported');

        $this->assertInstanceOf(KernelTestSupportedRuntime::class, $this->call(Kernel::create(), 'selectRuntime'));
    }

    public function testTheConfigSettingSelectsTheRuntimeWhenNothingElseDoes(): void
    {
        WorkerRuntimeRegistry::register('kernel-supported', KernelTestSupportedRuntime::class);
        Config::set('core.worker_runtime', 'kernel-supported');

        $this->assertInstanceOf(KernelTestSupportedRuntime::class, $this->call(Kernel::create(), 'selectRuntime'));
    }

    /**
     * The documented resolution order, highest first: create() option,
     * $QUIOTE_WORKER_RUNTIME, core.worker_runtime, auto-detection.
     */
    public function testTheKernelOptionWinsOverTheEnvironmentAndConfig(): void
    {
        WorkerRuntimeRegistry::register('kernel-supported', KernelTestSupportedRuntime::class);
        putenv('QUIOTE_WORKER_RUNTIME=sapi');
        Config::set('core.worker_runtime', 'sapi');

        $runtime = $this->call(Kernel::create(['worker_runtime' => 'kernel-supported']), 'selectRuntime');

        $this->assertInstanceOf(KernelTestSupportedRuntime::class, $runtime);
    }

    public function testTheEnvironmentWinsOverConfig(): void
    {
        WorkerRuntimeRegistry::register('kernel-supported', KernelTestSupportedRuntime::class);
        putenv('QUIOTE_WORKER_RUNTIME=kernel-supported');
        Config::set('core.worker_runtime', 'sapi');

        $this->assertInstanceOf(KernelTestSupportedRuntime::class, $this->call(Kernel::create(), 'selectRuntime'));
    }

    public function testNothingConfiguredFallsBackToDetection(): void
    {
        $this->assertInstanceOf(SapiRuntime::class, $this->call(Kernel::create(), 'selectRuntime'));
    }

    /** "auto" is the explicit spelling of the default, from either source. */
    public function testAutoMeansDetection(): void
    {
        $this->assertInstanceOf(SapiRuntime::class, $this->call(Kernel::create(['worker_runtime' => 'auto']), 'selectRuntime'));

        putenv('QUIOTE_WORKER_RUNTIME=auto');
        $this->assertInstanceOf(SapiRuntime::class, $this->call(Kernel::create(), 'selectRuntime'));
    }

    public function testAnEmptyConfigSettingFallsBackToDetection(): void
    {
        Config::set('core.worker_runtime', '');

        $this->assertInstanceOf(SapiRuntime::class, $this->call(Kernel::create(), 'selectRuntime'));
    }

    /**
     * Silently downgrading a named runtime to one-request-per-process would
     * turn a production deployment into something far slower without saying
     * so, which is why this refuses to start instead.
     */
    public function testANamedRuntimeThatDoesNotClaimTheProcessRefusesToStart(): void
    {
        WorkerRuntimeRegistry::register('roadrunner', KernelTestUnsupportedRuntime::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('it reports that it is not hosting this process');

        $this->call(Kernel::create(['worker_runtime' => 'roadrunner']), 'selectRuntime');
    }

    /** The message has to name where the request came from, or there is nowhere to go and fix it. */
    public function testTheRefusalNamesTheSourceOfTheRequest(): void
    {
        WorkerRuntimeRegistry::register('roadrunner', KernelTestUnsupportedRuntime::class);
        putenv('QUIOTE_WORKER_RUNTIME=roadrunner');

        try {
            $this->call(Kernel::create(), 'selectRuntime');
            $this->fail('an unsupported runtime must not resolve');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('$QUIOTE_WORKER_RUNTIME', $e->getMessage());
            $this->assertStringContainsString('RoadRunner sets $RR_MODE=http', $e->getMessage());
        }
    }

    public function testTheRefusalNamesTheConfigSettingWhenThatIsTheSource(): void
    {
        WorkerRuntimeRegistry::register('roadrunner', KernelTestUnsupportedRuntime::class);
        Config::set('core.worker_runtime', 'roadrunner');

        try {
            $this->call(Kernel::create(), 'selectRuntime');
            $this->fail('an unsupported runtime must not resolve');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('the "core.worker_runtime" setting', $e->getMessage());
        }
    }

    public function testTheRefusalNamesTheKernelOptionWhenThatIsTheSource(): void
    {
        WorkerRuntimeRegistry::register('roadrunner', KernelTestUnsupportedRuntime::class);

        try {
            $this->call(Kernel::create(['worker_runtime' => 'roadrunner']), 'selectRuntime');
            $this->fail('an unsupported runtime must not resolve');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('the "worker_runtime" kernel option', $e->getMessage());
        }
    }

    /**
     * The hint is how someone reading the refusal learns what the runtime
     * looks for, so each known runtime names its own detection signal rather
     * than the generic fallback.
     *
     * @param class-string<WorkerRuntimeInterface> $runtimeClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('runtimeDetectionHints')]
    public function testTheRefusalExplainsHowThatRuntimeIsDetected(
        string $runtimeClass,
        string $alias,
        string $expectedHint,
    ): void {
        WorkerRuntimeRegistry::register($alias, $runtimeClass);

        try {
            $this->call(Kernel::create(['worker_runtime' => $alias]), 'selectRuntime');
            $this->fail('an unsupported runtime must not resolve');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedHint, $e->getMessage());
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function runtimeDetectionHints(): array
    {
        return [
            'frankenphp' => [KernelTestFrankenPhpStub::class, 'frankenphp', 'frankenphp_handle_request()'],
            'roadrunner' => [KernelTestUnsupportedRuntime::class, 'roadrunner', '$RR_MODE=http'],
            'swoole' => [KernelTestSwooleStub::class, 'swoole', 'ext-swoole'],
        ];
    }

    /** An unknown alias has no bespoke hint, so it points at the runtime's own check. */
    public function testAnUnknownAliasFallsBackToAGenericDetectionHint(): void
    {
        WorkerRuntimeRegistry::register('kernel-unknown', KernelTestUnsupportedAliasRuntime::class);

        try {
            $this->call(Kernel::create(['worker_runtime' => 'kernel-unknown']), 'selectRuntime');
            $this->fail('an unsupported runtime must not resolve');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("see that runtime's isSupported()", $e->getMessage());
        }
    }

    // --- worker budgets ----------------------------------------------------

    public function testMaxRequestsIsUnlimitedByDefault(): void
    {
        $this->assertSame(0, $this->call(Kernel::create(), 'maxRequests'));
    }

    public function testMaxRequestsComesFromConfig(): void
    {
        Config::set('core.worker.max_requests', 500);

        $this->assertSame(500, $this->call(Kernel::create(), 'maxRequests'));
    }

    /** A negative budget would stop the loop before its first request. */
    public function testANegativeMaxRequestsIsClampedToUnlimited(): void
    {
        Config::set('core.worker.max_requests', -5);

        $this->assertSame(0, $this->call(Kernel::create(), 'maxRequests'));
    }

    public function testTheCleanupIntervalDefaultsToAThousandRequests(): void
    {
        $this->assertSame(1000, $this->call(Kernel::create(), 'cleanupInterval'));
    }

    public function testTheCleanupIntervalComesFromConfig(): void
    {
        Config::set('core.worker.cleanup_interval', 250);

        $this->assertSame(250, $this->call(Kernel::create(), 'cleanupInterval'));
    }

    public function testTheEnvironmentOverridesTheConfiguredCleanupInterval(): void
    {
        Config::set('core.worker.cleanup_interval', 250);
        putenv('QUIOTE_MAX_REQUESTS=64');

        $this->assertSame(64, $this->call(Kernel::create(), 'cleanupInterval'));
    }

    public function testANonNumericCleanupIntervalEnvironmentValueIsIgnored(): void
    {
        Config::set('core.worker.cleanup_interval', 250);
        putenv('QUIOTE_MAX_REQUESTS=lots');

        $this->assertSame(250, $this->call(Kernel::create(), 'cleanupInterval'));
    }

    /** A cleanup every zero requests would mean cleaning up on every one of them. */
    public function testTheCleanupIntervalIsAtLeastOne(): void
    {
        Config::set('core.worker.cleanup_interval', 0);
        $this->assertSame(1, $this->call(Kernel::create(), 'cleanupInterval'));

        putenv('QUIOTE_MAX_REQUESTS=0');
        $this->assertSame(1, $this->call(Kernel::create(), 'cleanupInterval'));
    }
}

/** Unsupported, reporting the frankenphp alias so its detection hint is selected. */
final class KernelTestFrankenPhpStub implements WorkerRuntimeInterface
{
    public static function isSupported(): bool
    {
        return false;
    }

    public static function alias(): string
    {
        return 'frankenphp';
    }

    public static function detectionPriority(): int
    {
        return 100;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: true);
    }

    public function run(WorkerLoop $loop): void
    {
    }
}

/** Unsupported, reporting the swoole alias so its detection hint is selected. */
final class KernelTestSwooleStub implements WorkerRuntimeInterface
{
    public static function isSupported(): bool
    {
        return false;
    }

    public static function alias(): string
    {
        return 'swoole';
    }

    public static function detectionPriority(): int
    {
        return 100;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: true);
    }

    public function run(WorkerLoop $loop): void
    {
    }
}

/** Unsupported, with an alias no detection hint knows about. */
final class KernelTestUnsupportedAliasRuntime implements WorkerRuntimeInterface
{
    public static function isSupported(): bool
    {
        return false;
    }

    public static function alias(): string
    {
        return 'kernel-unknown';
    }

    public static function detectionPriority(): int
    {
        return 100;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: false);
    }

    public function run(WorkerLoop $loop): void
    {
    }
}
