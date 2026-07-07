<?php

namespace Quiote\Plugin;

/**
 * A Quiote plugin: a self-contained bundle that contributes to the framework
 * through the seams that already exist (config defaults, DI services,
 * middleware, event listeners, routes/modules, output types, commands, HTTP
 * clients) via a single {@see register()} lifecycle call — this is the
 * mechanism the framework's "unopinionated core + opinionated drop-ins"
 * philosophy is built on.
 *
 * Plugins are registered either programmatically ({@see PluginManager::add()}
 * before bootstrap) or declaratively via the `plugins` config key (a list of
 * plugin class-strings), and {@see register()} is invoked once during
 * {@see \Quiote\Quiote::bootstrap()} — after settings load, before contexts are
 * created — in deterministic order.
 *
 * A diagnostics/logging name for the plugin comes from either
 * {@see \Quiote\Plugin\Attribute\Plugin}'s `name` argument or, for a plugin
 * whose name can't be a compile-time constant, from implementing
 * {@see NamedPlugin} instead — see {@see PluginManager} for how the two are
 * resolved.
 */
interface PluginInterface
{
    /**
     * Contribute to the framework. Called exactly once at boot. Every
     * contribution routes through {@see PluginRegistrar} to an existing seam;
     * a plugin does not touch framework internals directly.
     */
    public function register(PluginRegistrar $registrar): void;
}
