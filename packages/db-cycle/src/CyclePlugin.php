<?php

namespace Quiote\Database\Adapter\Cycle;

use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginRegistrar;

/**
 * Enables the `cycle` database driver alias. Add this class to the `plugins`
 * config key to write `class="cycle"` in `databases.xml`.
 *
 * Extracts to `quioteframework/quiote-cycle` unchanged.
 */
#[PluginAttribute]
final class CyclePlugin implements PluginInterface
{
    public function name(): string
    {
        return 'quiote/cycle';
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->databaseDriver('cycle', CycleDatabase::class);
    }
}
