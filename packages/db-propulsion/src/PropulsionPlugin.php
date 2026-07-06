<?php

namespace Quiote\Database\Adapter\Propulsion;

use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginRegistrar;

/**
 * Enables the `propulsion` database driver alias. Add this class to the
 * `plugins` config key to write `class="propulsion"` in `databases.xml`.
 */
#[PluginAttribute]
final class PropulsionPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'quiote/propulsion';
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->databaseDriver('propulsion', PropulsionDatabase::class);
    }
}
