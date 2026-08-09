<?php

namespace Quiote\Security\Csrf;

use Quiote\Context;
use Quiote\Security\Csrf\Middleware\CsrfInjectionMiddleware;
use Quiote\Security\Csrf\Middleware\CsrfValidationMiddleware;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginRegistrar;

/**
 * Registers the CSRF middleware pair through the generic plugin seam instead
 * of {@see \Quiote\Middleware\MiddlewarePipeline} hardcoding them. Both
 * middleware still carry their own `#[Middleware(...)]` attribute for
 * ordering — this plugin only supplies the per-context factory
 * ({@see PluginRegistrar::attributedMiddleware()}) so each middleware gets
 * *this* pipeline's own `Controller`, not a container-autowired (and
 * possibly unrelated, for apps with a custom Controller subclass) one.
 *
 * Physically split into its own package, `packages/csrf/` (developed
 * in-tree, symlinked via a path repository) — not yet pushed to a
 * standalone repo, and `Quiote::bootstrap()` still runs this
 * plugin unconditionally today (see the "core default" note there). Once that
 * core-default call is deleted, CSRF becomes opt-in via the `plugins` config
 * key, exactly like {@see \Quiote\Mcp\McpPlugin} already is.
 */
#[PluginAttribute(name: 'quiote/csrf')]
final class CsrfPlugin implements PluginInterface
{
    /**
     * Registers the CSRF injection and validation middleware.
     *
     * Each is registered with an explicit factory that pulls the `Controller`
     * out of the context being registered against, so a middleware instance is
     * bound to that pipeline's own controller rather than an autowired one.
     * Ordering still comes from each middleware's own `#[Middleware]`
     * attribute.
     */
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->attributedMiddleware(
            CsrfInjectionMiddleware::class,
            static fn(Context $context) => new CsrfInjectionMiddleware($context->getContainer()->get(\Quiote\Controller\Controller::class)),
        );
        $registrar->attributedMiddleware(
            CsrfValidationMiddleware::class,
            static fn(Context $context) => new CsrfValidationMiddleware($context->getContainer()->get(\Quiote\Controller\Controller::class)),
        );
    }
}
