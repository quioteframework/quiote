<?php
declare(strict_types=1);

namespace QuioteTestGeneratedCallbacks;
use Symfony\Component\Routing\RouteCollection;

/**
 * Symfony routes aggregate split per module (0 total; built 2025-08-18T12:36:32+00:00)
 * Source: test/sandbox/app/Config/tests/routing_callbacks.xml
 */
final class Routes {
    /**
     * @return array{RouteCollection, array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}>}
     */
    public static function build(): array {
        $routes = new RouteCollection();
        $meta = [];
        return [$routes, $meta];
    }
}
