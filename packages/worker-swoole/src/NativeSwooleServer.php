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

    public function onWorkerStart(callable $listener): void
    {
        // Swoole passes ($server, $workerId); neither is needed, and swallowing
        // them keeps the interface free of extension types.
        $this->server->on('workerStart', static function () use ($listener): void {
            $listener();
        });
    }

    public function onRequest(callable $listener): void
    {
        $this->server->on('request', $listener);
    }

    public function start(): void
    {
        $this->server->start();
    }
}
