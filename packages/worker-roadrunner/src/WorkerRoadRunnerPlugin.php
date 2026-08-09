<?php

declare(strict_types=1);

namespace Quiote\Runtime\RoadRunner;

use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Runtime\Worker\WorkerRuntimeRegistry;

/**
 * Registers the `roadrunner` worker-runtime alias.
 *
 * Registration has to happen during plugin boot rather than at runtime
 * selection, and it does: `Quiote::bootstrap()` boots plugins before
 * `Kernel::run()` picks a runtime, so the alias -- and its `isSupported()`
 * vote during auto-detection -- are both in place by then.
 */
#[PluginAttribute(name: 'quiote/worker-roadrunner')]
final class WorkerRoadRunnerPlugin implements PluginInterface
{
    /**
     * Publishes the `worker.roadrunner.chunk_size` default and the runtime alias.
     *
     * Adds `roadrunner` to {@see WorkerRuntimeRegistry}, which is what lets both
     * an explicitly configured alias and auto-detection find the runtime.
     */
    public function register(PluginRegistrar $registrar): void
    {
        // Bytes per streamed frame for an SSE response. Only an upper bound: the
        // stream hands back a whole event at a time, so small events are not
        // held back waiting to fill a chunk.
        $registrar->configDefault('worker.roadrunner.chunk_size', RoadRunnerResponseEmitter::DEFAULT_CHUNK_SIZE);

        WorkerRuntimeRegistry::register('roadrunner', RoadRunnerRuntime::class);
    }
}
