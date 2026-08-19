<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Propulsion;

use Propulsion\Propulsion;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Replay\Recording\EffectSourceRegistry;

/**
 * Wires Propulsion's own query observer seam into `quioteframework/replay`'s
 * generic effect-recording seam, through the same plugin mechanism every
 * other Quiote package uses.
 *
 * Unlike `Quiote\Replay\ReplayPlugin` (which guards every Propulsion
 * reference behind `class_exists()`, since it must not hard-depend on any
 * ORM), this plugin's whole reason to exist is that Propulsion *is*
 * installed -- an app that doesn't want this integration simply doesn't
 * require this package.
 */
#[PluginAttribute(name: 'quioteframework/replay-propulsion')]
final class ReplayPropulsionPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        // Registered exactly once, here at boot, never per-request -- see
        // PropulsionQueryRecorder's own docblock for why.
        // No Redactor is resolved here: register() runs during plugin boot, potentially before an
        // application's own replay.redact.* config is loaded, and a Redactor built now would
        // silently carry the hardcoded defaults for the life of the process. The recorder resolves
        // one per query instead -- see PropulsionQueryRecorder::__construct().
        Propulsion::addQueryObserver(new PropulsionQueryRecorder());
        EffectSourceRegistry::register(new PropulsionEffectSource());

        $registrar->stateReset('quioteframework/replay-propulsion', static function (): void {
            Propulsion::clearQueryObservers();
        });
    }
}
