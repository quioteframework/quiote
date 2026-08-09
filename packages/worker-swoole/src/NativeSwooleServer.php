<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

use Swoole\Http\Server as SwooleHttpServer;

/** Pure delegation onto the real Swoole server. */
final class NativeSwooleServer implements SwooleServerInterface
{
    public function __construct(private readonly SwooleHttpServer $server)
    {
    }

    /**
     * {@inheritDoc}
     *
     * Registers on Swoole's `workerStart` event, dropping the server and worker
     * id arguments Swoole passes so the listener stays free of extension types.
     */
    public function onWorkerStart(callable $listener): void
    {
        // Swoole passes ($server, $workerId); neither is needed, and swallowing
        // them keeps the interface free of extension types.
        $this->server->on('workerStart', static function () use ($listener): void {
            $listener();
        });
    }

    /** Registers the listener on Swoole's `request` event, arguments unchanged. */
    public function onRequest(callable $listener): void
    {
        $this->server->on('request', $listener);
    }

    /** {@inheritDoc} */
    public function start(): void
    {
        $this->server->start();
    }
}
