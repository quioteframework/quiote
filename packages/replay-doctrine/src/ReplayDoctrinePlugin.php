<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Doctrine;

use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Replay\Recording\EffectSourceRegistry;

/**
 * Wires Doctrine's own DBAL driver-middleware seam into
 * `quioteframework/replay`'s generic effect-recording seam, through the same
 * plugin mechanism every other Quiote package uses.
 *
 * Registers {@see ReplayDoctrineDatabase}/{@see ReplayDoctrineDbalDatabase}
 * -- thin subclasses that install {@see DoctrineRecordingMiddleware} at
 * connect time -- under the same `doctrine`/`doctrine_dbal` driver aliases
 * `quioteframework/db-doctrine`'s own `DoctrinePlugin` registers.
 * {@see \Quiote\Plugin\PluginRegistrar::databaseDriver()} is last-writer-wins
 * (unlike `service()`'s set-if-absent), so an app that loads this plugin
 * after `DoctrinePlugin` gets the recording subclasses transparently, with
 * no `databases.xml` change.
 */
#[PluginAttribute(name: 'quioteframework/replay-doctrine')]
final class ReplayDoctrinePlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar
            ->databaseDriver('doctrine', ReplayDoctrineDatabase::class)
            ->databaseDriver('doctrine_dbal', ReplayDoctrineDbalDatabase::class);

        EffectSourceRegistry::register(new DoctrineEffectSource());
    }
}
