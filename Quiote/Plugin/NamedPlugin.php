<?php

namespace Quiote\Plugin;

/**
 * Opt-in for a plugin whose diagnostics/logging name can't be a compile-time
 * constant (e.g. it's computed from config, an environment value, or an
 * instance the plugin was constructed with). Most plugins don't need this —
 * naming the plugin via {@see \Quiote\Plugin\Attribute\Plugin}'s `name`
 * argument is enough. {@see PluginManager} prefers this interface's
 * {@see name()} over the attribute's name when a plugin implements both.
 */
interface NamedPlugin extends PluginInterface
{
    /** A stable, human-readable identifier for diagnostics/logging. */
    public function name(): string;
}
