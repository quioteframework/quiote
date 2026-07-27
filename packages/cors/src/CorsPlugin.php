<?php

namespace Quiote\Security\Cors;

use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;

/**
 * Registers {@see CorsMiddleware} through the generic plugin seam, opt-in via
 * `cors.enabled` (the middleware itself no-ops when it's false, so simply
 * installing this package doesn't turn CORS on for every app).
 */
#[PluginAttribute(name: 'quiote/cors')]
final class CorsPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('cors.enabled', false);
        $registrar->configDefault('cors.allowed_origins', []);
        $registrar->configDefault('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $registrar->configDefault('cors.allowed_headers', []);
        $registrar->configDefault('cors.exposed_headers', []);
        $registrar->configDefault('cors.allow_credentials', false);
        $registrar->configDefault('cors.max_age', 0);

        $registrar->attributedMiddleware(CorsMiddleware::class);
    }
}
