<?php

namespace Quiote\Exception\Rendering\Whoops;

use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginRegistrar;

/**
 * Opt-in entry point for the Whoops developer-exception renderer. Adding
 * this class to the `plugins` config key registers {@see WhoopsRenderer} as
 * the renderer {@see \Quiote\Middleware\ErrorHandlingMiddleware} uses when
 * `core.developer_exceptions` is true (see
 * {@see \Quiote\Exception\Rendering\ExceptionRendererRegistry}); without it,
 * `core.developer_exceptions=true` still just falls back to `SafeRenderer`.
 *
 * Unlike {@see \Quiote\Security\Csrf\CsrfPlugin} (kept on by default —
 * CSRF protection is a security default, not merely a packaging concern),
 * Whoops has no core-default registration: nothing bad happens if it's
 * simply absent, so it follows the same fully opt-in model as
 * {@see \Quiote\Mcp\McpPlugin} and the ORM adapter plugins.
 */
#[PluginAttribute]
final class WhoopsPlugin implements PluginInterface
{
    public function name(): string
    {
        return 'quiote/whoops';
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->developerExceptionRenderer(static fn() => new WhoopsRenderer());
    }
}
