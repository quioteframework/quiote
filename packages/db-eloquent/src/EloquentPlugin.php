<?php

namespace Quiote\Database\Adapter\Eloquent;

use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginRegistrar;

/**
 * Enables the `eloquent` database driver alias. Add this class to the `plugins`
 * config key to write `class="eloquent"` in `databases.xml`.
 *
 * When the adapters are extracted into standalone composer packages, this plugin
 * (and its adapter) move to `quioteframework/quiote-eloquent` unchanged.
 */
#[PluginAttribute]
final class EloquentPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'quiote/eloquent';
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->databaseDriver('eloquent', EloquentDatabase::class);
    }
}
