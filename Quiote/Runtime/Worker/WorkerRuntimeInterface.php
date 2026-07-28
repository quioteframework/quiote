<?php

declare(strict_types=1);

namespace Quiote\Runtime\Worker;

/**
 * A host that drives Quiote's request loop: the PHP SAPI, a FrankenPHP worker,
 * a RoadRunner worker, a Swoole HTTP server.
 *
 * The runtime owns both ends of a request -- acquiring it and emitting the
 * response -- because that is precisely what differs between hosts. A SAPI
 * takes its input from superglobals and writes with header()/echo; a
 * CLI-hosted server is handed a request object and hands back a response
 * object, and can do neither of those things. Everything in between is
 * {@see WorkerLoop}'s job, so a runtime is typically well under a hundred lines.
 *
 * Runtimes register themselves with {@see WorkerRuntimeRegistry} under a short
 * alias; core ships `sapi` and `frankenphp`, and packages contribute the rest
 * from their plugin.
 */
interface WorkerRuntimeInterface
{
    /**
     * Whether this runtime is the one actually hosting the current process.
     * Must be cheap and free of side effects -- it is called for every
     * registered runtime during auto-detection, before anything has booted.
     */
    public static function isSupported(): bool;

    /** The registry alias, e.g. "frankenphp". */
    public static function alias(): string;

    /**
     * Tie-break for auto-detection: the highest priority among the runtimes
     * reporting isSupported() wins. {@see SapiRuntime} sits at PHP_INT_MIN so
     * it is only ever chosen when nothing else claims the process.
     */
    public static function detectionPriority(): int;

    public function capabilities(): WorkerRuntimeCapabilities;

    /**
     * Serve requests until the host says to stop. Implementations acquire a
     * request, pass it to {@see WorkerLoop::handle()} (which never throws),
     * emit the response, and call {@see WorkerLoop::afterRequest()} at the
     * boundary -- the last one in a `finally`, so state is reset even when
     * emission fails.
     */
    public function run(WorkerLoop $loop): void;
}
