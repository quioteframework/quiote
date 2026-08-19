<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Eloquent;

use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Replay\Recording\EffectSourceRegistry;

/**
 * Wires Eloquent's own `QueryExecuted` event seam into
 * `quioteframework/replay`'s generic effect-recording seam, through the same
 * plugin mechanism every other Quiote package uses.
 *
 * Registers {@see ReplayEloquentDatabase} -- a thin subclass that attaches
 * {@see EloquentQueryRecorder} at connect time -- under the same `eloquent`
 * driver alias `quioteframework/db-eloquent`'s own `EloquentPlugin` registers.
 * {@see \Quiote\Plugin\PluginRegistrar::databaseDriver()} is last-writer-wins
 * (unlike `service()`'s set-if-absent), so an app that loads this plugin
 * after `EloquentPlugin` gets the recording subclass transparently, with no
 * `databases.xml` change.
 */
#[PluginAttribute(name: 'quioteframework/replay-eloquent')]
final class ReplayEloquentPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->databaseDriver('eloquent', ReplayEloquentDatabase::class);

        EffectSourceRegistry::register(new EloquentEffectSource());

        $registrar->stateReset('quioteframework/replay-eloquent', static function (): void {
            EloquentQueryRecorder::reset();
        });
    }
}
