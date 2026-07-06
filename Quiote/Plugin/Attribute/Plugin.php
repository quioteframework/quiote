<?php

namespace Quiote\Plugin\Attribute;

use Attribute;

/**
 * Marks a class as a sanctioned plugin entry point. Required on every class
 * activated through a class-string -- `plugins.{xml,php,yaml,yml}` or
 * {@see \Quiote\Plugin\PluginManager::add()} passed a string -- so that
 * naming a class there is not, by itself, enough to make it run: the class
 * must also have deliberately opted in by carrying this attribute. This is
 * a defense-in-depth measure, not the activation mechanism itself -- the
 * attribute makes a {@see \Quiote\Plugin\PluginInterface} class eligible;
 * an explicit `plugins.*` entry (or an `add()` call) is still what turns it
 * on. A composer package can ship a class carrying this attribute and it
 * still does nothing until an app deliberately names that class in its own
 * `plugins.*` file or code -- installing the package alone can't activate
 * it, and merely being autoloadable is not activation.
 *
 * {@see \Quiote\Plugin\PluginManager::add()} passed an already-constructed
 * `PluginInterface` instance (`new SomePlugin()`) skips this check --
 * that call site already is the trust boundary, since the caller's own code
 * named the class directly rather than routing it through a string that
 * could come from a config file.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Plugin
{
    public function __construct(public ?string $name = null)
    {
    }
}
