<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

use RuntimeException;
use Swoole\Http\Server as SwooleHttpServer;

/**
 * Builds the real \Swoole\Http\Server. The only place in this package that
 * names it, alongside {@see SwooleRequestSnapshotFactory} and
 * {@see SwooleResponseWriter}.
 *
 * SWOOLE_BASE (single-process-per-connection) rather than SWOOLE_PROCESS: there
 * is no separate master routing layer to gain from, and BASE keeps each request
 * inside one worker for its whole lifetime, which is what Quiote's
 * process-global state requires. See {@see SwooleRuntime} for the rest of that
 * reasoning.
 */
final class NativeSwooleServerFactory implements SwooleServerFactory
{
    private bool $extensionAvailable;

    /**
     * @param bool|null $extensionAvailable Overridable so the missing-extension
     *        guard is testable on a machine that does have ext-swoole, instead of
     *        being a test that skips itself depending on the environment.
     */
    public function __construct(?bool $extensionAvailable = null)
    {
        $this->extensionAvailable = $extensionAvailable ?? class_exists(SwooleHttpServer::class);
    }

    /**
     * Builds a \Swoole\Http\Server bound to $host:$port in SWOOLE_BASE mode.
     *
     * The settings array is passed to the server's own `set()` untouched. The
     * server is created but not started; {@see SwooleServerInterface::start()}
     * does that.
     *
     * @throws RuntimeException if ext-swoole is not available in this process.
     */
    public function create(string $host, int $port, array $settings): SwooleServerInterface
    {
        if (!$this->extensionAvailable) {
            throw new RuntimeException(
                'The "swoole" worker runtime was selected but ext-swoole is not installed. '
                . 'Install it with `pecl install swoole` (5.1 or newer), or choose a different '
                . 'core.worker_runtime.'
            );
        }

        $mode = defined('SWOOLE_BASE') ? (int) constant('SWOOLE_BASE') : 1;
        $server = new SwooleHttpServer($host, $port, $mode);
        $server->set($settings);

        return new NativeSwooleServer($server);
    }
}
