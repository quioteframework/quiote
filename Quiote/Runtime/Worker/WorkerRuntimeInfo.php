<?php

declare(strict_types=1);

namespace Quiote\Runtime\Worker;

use Throwable;

/**
 * "Which runtime are we on, and what can it do?" -- the process-wide query
 * surface for code outside the Runtime namespace that needs to behave
 * differently in a persistent worker.
 *
 * Kernel installs the selected runtime here before starting it. Anything asking
 * earlier than that still gets a usable answer: boot-time listeners run inside
 * Kernel::bootstrap(), which happens *before* runtime selection, so a query
 * with nothing installed falls back to auto-detection over the registry and
 * caches the result. Plugins have already registered their aliases by then, so
 * detection sees the full set.
 */
final class WorkerRuntimeInfo
{
    private static ?WorkerRuntimeInterface $runtime = null;
    private static ?WorkerRuntimeCapabilities $detectedCapabilities = null;

    private function __construct()
    {
    }

    /**
     * Records the runtime that is about to serve this process, process-wide.
     *
     * Called by the Kernel once selection is done, before the runtime starts.
     * Every later query answers from this instance instead of auto-detecting,
     * and any capabilities cached from an earlier detection are dropped.
     */
    public static function install(WorkerRuntimeInterface $runtime): void
    {
        self::$runtime = $runtime;
        self::$detectedCapabilities = null;
    }

    /**
     * Whether {@see install()} has already run.
     *
     * False means queries below are still answering from auto-detection rather
     * than from the runtime the Kernel actually selected.
     */
    public static function isInstalled(): bool
    {
        return self::$runtime !== null;
    }

    /** The installed runtime's alias, or the detected one's when nothing is installed yet. */
    public static function alias(): string
    {
        $runtime = self::$runtime;
        if ($runtime !== null) {
            return $runtime::alias();
        }
        return WorkerRuntimeRegistry::detect()::alias();
    }

    /**
     * What the hosting runtime does for itself: persistence, superglobals,
     * SAPI output, streaming, forking.
     *
     * Answers from the installed runtime when there is one. Otherwise the
     * registry is auto-detected and the detected runtime is instantiated to ask
     * it; that answer is cached for the process and invalidated by
     * {@see install()} or {@see reset()}. A detected runtime whose constructor
     * cannot run here still yields a usable answer, with `persistent` derived
     * from whether it is {@see SapiRuntime}.
     */
    public static function capabilities(): WorkerRuntimeCapabilities
    {
        $runtime = self::$runtime;
        if ($runtime !== null) {
            return $runtime->capabilities();
        }
        return self::$detectedCapabilities ??= self::detectCapabilities();
    }

    /**
     * The question almost every caller actually has: is this process going to
     * handle more than one request? Drives batch-vs-simple telemetry export,
     * shutdown-function registration, and so on.
     */
    public static function isPersistent(): bool
    {
        return self::capabilities()->persistent;
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$runtime = null;
        self::$detectedCapabilities = null;
    }

    private static function detectCapabilities(): WorkerRuntimeCapabilities
    {
        $class = WorkerRuntimeRegistry::detect();
        try {
            return (new $class())->capabilities();
        } catch (Throwable) {
            // A runtime whose constructor needs something we don't have here
            // (a server handle, an extension) still tells us the one thing that
            // matters: only SapiRuntime is non-persistent.
            return WorkerRuntimeCapabilities::sapi(persistent: $class !== SapiRuntime::class);
        }
    }
}
