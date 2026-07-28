<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

/**
 * The slice of \Swoole\Http\Server the runtime drives, so the loop's wiring can
 * be asserted without ext-swoole (and without actually binding a port).
 */
interface SwooleServerInterface
{
    /**
     * Runs once in each freshly forked worker child, before it takes a request.
     *
     * @param callable(): void $listener
     */
    public function onWorkerStart(callable $listener): void;

    /**
     * @param callable(\Swoole\Http\Request, \Swoole\Http\Response): void $listener
     */
    public function onRequest(callable $listener): void;

    /** Binds and serves; does not return until the server stops. */
    public function start(): void;
}
