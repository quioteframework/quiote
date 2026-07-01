<?php
declare(strict_types=1);

namespace QuioteTestGeneratedTest1;
use Symfony\Component\Routing\RouteCollection;
use QuioteTestGeneratedTest1\Modules\Test1Routes;
use QuioteTestGeneratedTest1\Modules\Module3Routes;
use QuioteTestGeneratedTest1\Modules\Module4Routes;

/**
 * Symfony routes aggregate split per module (5 total; built 2025-08-18T13:18:24+00:00)
 * Source: test/sandbox/app/Config/tests/routing_simple.xml
 */
final class Routes {
    public static function build(): array {
        $routes = new RouteCollection();
        $meta = [];
        Test1Routes::addRoutes($routes, $meta);
        Module3Routes::addRoutes($routes, $meta);
        Module4Routes::addRoutes($routes, $meta);
        return [$routes, $meta];
    }
}
