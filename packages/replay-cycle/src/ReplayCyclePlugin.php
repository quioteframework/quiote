<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Cycle;

use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Replay\Recording\EffectSourceRegistry;

/**
 * Wires Cycle's own PSR-3 logger seam into `quioteframework/replay`'s
 * generic effect-recording seam, through the same plugin mechanism every
 * other Quiote package uses.
 *
 * Registers {@see ReplayCycleDatabase} -- a thin subclass that installs
 * {@see CycleRecordingLogger} at connect time -- under the same `cycle`
 * driver alias `quioteframework/db-cycle`'s own `CyclePlugin` registers.
 * {@see \Quiote\Plugin\PluginRegistrar::databaseDriver()} is last-writer-wins
 * (unlike `service()`'s set-if-absent), so an app that loads this plugin
 * after `CyclePlugin` gets the recording subclass transparently, with no
 * `databases.xml` change.
 */
#[PluginAttribute(name: 'quioteframework/replay-cycle')]
final class ReplayCyclePlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->databaseDriver('cycle', ReplayCycleDatabase::class);

        EffectSourceRegistry::register(new CycleEffectSource());
    }
}
