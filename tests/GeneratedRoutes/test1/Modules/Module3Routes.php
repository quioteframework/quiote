<?php
declare(strict_types=1);

namespace QuioteTestGeneratedTest1\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module module3 (1 routes; built 2025-08-18T13:18:24+00:00)
 */
final class Module3Routes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        $routes->add('t1child3', new Route('/anchor/child3/{bar}', [
    '_module' => 'module3',
    '_action' => 'action3',
], [
    'bar' => 'child2',
]));
        $meta['t1child3'] = [
    'gen_path' => '/anchor/child3/{bar}',
    'cut' => false,
    'path' => '/anchor/child3/{bar}',
];
    }
}
