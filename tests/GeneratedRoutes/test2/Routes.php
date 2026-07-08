<?php
declare(strict_types=1);

namespace QuioteTestGeneratedTest2;
use Symfony\Component\Routing\RouteCollection;
use QuioteTestGeneratedTest2\Modules\t1Module1Routes;

/**
 * Symfony routes aggregate split per module (1 total; built 2025-08-18T12:51:47+00:00)
 * Source: test/sandbox/app/Config/tests/routing_simple.xml
 */
final class Routes {
    /**
     * @return array{RouteCollection, array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}>}
     */
    public static function build(): array {
        $routes = new RouteCollection();
        $meta = [];
        t1Module1Routes::addRoutes($routes, $meta);
        return [$routes, $meta];
    }
}
