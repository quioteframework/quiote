<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

/**
 * Creates the HTTP server {@see SwooleRuntime} binds and runs.
 *
 * The seam that keeps the runtime testable: {@see SwooleRuntime} takes one of these
 * optionally and falls back to {@see NativeSwooleServerFactory}, which builds a real
 * `Swoole\Http\Server` and refuses when ext-swoole is absent. A test supplies its own
 * implementation and gets a {@see SwooleServerInterface} double, so the host, port and
 * settings the runtime computed can be asserted without the extension installed.
 *
 * Implementors must return a server bound to the given host and port with `$settings`
 * applied, ready for the runtime to attach its worker-start and request handlers to.
 */
interface SwooleServerFactory
{
    /**
     * @param array<string, mixed> $settings Passed straight to Swoole's set().
     */
    public function create(string $host, int $port, array $settings): SwooleServerInterface;
}
