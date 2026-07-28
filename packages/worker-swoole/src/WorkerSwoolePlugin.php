<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Runtime\Swoole\Console\SwooleServeCommand;
use Quiote\Runtime\Worker\WorkerRuntimeRegistry;

/**
 * Registers the `swoole` worker-runtime alias, its settings, and the
 * `swoole:serve` launcher.
 */
#[PluginAttribute(name: 'quiote/worker-swoole')]
final class WorkerSwoolePlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('worker.swoole.host', SwooleRuntime::DEFAULT_HOST);
        $registrar->configDefault('worker.swoole.port', SwooleRuntime::DEFAULT_PORT);
        // One request at a time per process; scale with worker_num, never with
        // coroutines -- see SwooleRuntime for why.
        $registrar->configDefault('worker.swoole.worker_num', 1);
        $registrar->configDefault('worker.swoole.enable_coroutine', false);
        // 0 = let the worker live indefinitely; Swoole recycles it otherwise.
        $registrar->configDefault('worker.swoole.max_request', 0);
        $registrar->configDefault('worker.swoole.package_max_length', 8 * 1024 * 1024);
        // Swoole has no front-controller script, but Routing reads SCRIPT_NAME.
        $registrar->configDefault('worker.swoole.script_name', '/index.php');
        // Only true when Swoole itself terminates TLS, not when a proxy does.
        $registrar->configDefault('worker.swoole.ssl', false);

        WorkerRuntimeRegistry::register('swoole', SwooleRuntime::class);

        $registrar->command(SwooleServeCommand::class);
    }
}
