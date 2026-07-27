<?php

namespace Quiote\Security\Headers;

use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;

/**
 * Registers {@see SecurityHeadersMiddleware} through the generic plugin seam.
 * Unlike CORS/rate-limiting, this middleware is safe-by-default and enabled
 * out of the box (`security_headers.enabled` defaults to true) — the headers
 * it adds are broadly applicable hardening, not a per-app policy decision.
 */
#[PluginAttribute(name: 'quiote/security-headers')]
final class SecurityHeadersPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('security_headers.enabled', true);
        $registrar->configDefault('security_headers.csp', "default-src 'self'");
        $registrar->configDefault('security_headers.frame_options', 'DENY');
        $registrar->configDefault('security_headers.content_type_options', 'nosniff');
        $registrar->configDefault('security_headers.referrer_policy', 'strict-origin-when-cross-origin');
        $registrar->configDefault('security_headers.permissions_policy', '');
        $registrar->configDefault('security_headers.hsts', true);
        $registrar->configDefault('security_headers.hsts_max_age', 15_552_000);

        $registrar->attributedMiddleware(SecurityHeadersMiddleware::class);
    }
}
