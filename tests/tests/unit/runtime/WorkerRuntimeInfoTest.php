<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Runtime\Worker\SapiRuntime;
use Quiote\Runtime\Worker\WorkerLoop;
use Quiote\Runtime\Worker\WorkerRuntimeCapabilities;
use Quiote\Runtime\Worker\WorkerRuntimeInfo;
use Quiote\Runtime\Worker\WorkerRuntimeInterface;
use Quiote\Runtime\Worker\WorkerRuntimeRegistry;

/** A persistent runtime that claims the process, for exercising detection. */
final class InfoTestPersistentRuntime implements WorkerRuntimeInterface
{
    public static function isSupported(): bool
    {
        return true;
    }

    public static function alias(): string
    {
        return 'info-persistent';
    }

    public static function detectionPriority(): int
    {
        return 900;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return new WorkerRuntimeCapabilities(
            persistent: true,
            populatesSuperglobals: false,
            sapiOutput: false,
            streaming: true,
            forksWorkers: true,
        );
    }

    public function run(WorkerLoop $loop): void
    {
    }
}

/** Claims the process but cannot be constructed without its host present. */
final class InfoTestUnconstructableRuntime implements WorkerRuntimeInterface
{
    public function __construct()
    {
        throw new RuntimeException('needs a server handle that only exists under the real host');
    }

    public static function isSupported(): bool
    {
        return true;
    }

    public static function alias(): string
    {
        return 'info-unconstructable';
    }

    public static function detectionPriority(): int
    {
        return 950;
    }

    public function capabilities(): WorkerRuntimeCapabilities
    {
        return WorkerRuntimeCapabilities::sapi(persistent: true);
    }

    public function run(WorkerLoop $loop): void
    {
    }
}

final class WorkerRuntimeInfoTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        WorkerRuntimeInfo::reset();
        WorkerRuntimeRegistry::reset();
    }

    public function testNothingIsInstalledUntilTheKernelSelectsARuntime(): void
    {
        $this->assertFalse(WorkerRuntimeInfo::isInstalled());
    }

    public function testAnInstalledRuntimeAnswersEveryQuery(): void
    {
        WorkerRuntimeInfo::install(new InfoTestPersistentRuntime());

        $this->assertTrue(WorkerRuntimeInfo::isInstalled());
        $this->assertSame('info-persistent', WorkerRuntimeInfo::alias());
        $this->assertTrue(WorkerRuntimeInfo::isPersistent());
        $this->assertTrue(WorkerRuntimeInfo::capabilities()->forksWorkers);
        $this->assertFalse(WorkerRuntimeInfo::capabilities()->sapiOutput);
    }

    public function testWithNothingInstalledTheAnswerComesFromDetection(): void
    {
        // Boot-time listeners (telemetry picking batch vs simple export) run
        // inside Kernel::bootstrap(), before a runtime is installed, so this
        // fallback is the normal path for them rather than an edge case.
        WorkerRuntimeRegistry::register('info-persistent', InfoTestPersistentRuntime::class);

        $this->assertFalse(WorkerRuntimeInfo::isInstalled());
        $this->assertSame('info-persistent', WorkerRuntimeInfo::alias());
        $this->assertTrue(WorkerRuntimeInfo::isPersistent());
    }

    public function testUnderAPlainSapiDetectionReportsNonPersistent(): void
    {
        $this->assertSame('sapi', WorkerRuntimeInfo::alias());
        $this->assertFalse(WorkerRuntimeInfo::isPersistent());
    }

    public function testARuntimeThatCannotBeConstructedStillReportsItsPersistence(): void
    {
        WorkerRuntimeRegistry::register('info-unconstructable', InfoTestUnconstructableRuntime::class);

        // Falling back to "anything but SapiRuntime is persistent" beats
        // propagating a constructor failure out of a boot-time capability query.
        $this->assertTrue(WorkerRuntimeInfo::isPersistent());
    }

    public function testInstallingOverridesAPreviouslyDetectedAnswer(): void
    {
        WorkerRuntimeRegistry::register('info-persistent', InfoTestPersistentRuntime::class);
        $this->assertTrue(WorkerRuntimeInfo::isPersistent());

        WorkerRuntimeInfo::install(new SapiRuntime());

        $this->assertFalse(WorkerRuntimeInfo::isPersistent());
        $this->assertSame('sapi', WorkerRuntimeInfo::alias());
    }

    public function testResetForgetsBothTheInstalledAndTheDetectedAnswer(): void
    {
        WorkerRuntimeInfo::install(new InfoTestPersistentRuntime());
        $this->assertTrue(WorkerRuntimeInfo::isInstalled());

        WorkerRuntimeInfo::reset();

        $this->assertFalse(WorkerRuntimeInfo::isInstalled());
        $this->assertFalse(WorkerRuntimeInfo::isPersistent());
    }
}
