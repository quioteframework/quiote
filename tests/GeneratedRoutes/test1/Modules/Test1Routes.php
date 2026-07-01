<?php
declare(strict_types=1);

namespace QuioteTestGeneratedTest1\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module test1 (3 routes; built 2025-08-18T13:18:24+00:00)
 */
final class Test1Routes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        $routes->add('t1child1', new Route('/anchor/child1', [
    '_module' => 'test1',
    '_action' => 'action1',
], []));
        $meta['t1child1'] = [
    'gen_path' => '/anchor/child1',
    'cut' => false,
    'path' => '/anchor/child1',
];
        $routes->add('t1child2', new Route('/anchor/{foo}', [
    '_module' => 'test1',
    '_action' => 'action2',
], [
    'foo' => 'child2',
]));
        $meta['t1child2'] = [
    'gen_path' => '/anchor/{foo}',
    'cut' => false,
    'path' => '/anchor/{foo}',
];
        $routes->add('testWithChild', new Route('/anchor', [
    '_module' => 'test1',
], []));
        $meta['testWithChild'] = [
    'gen_path' => '/anchor',
    'cut' => false,
    'path' => '/anchor',
];
    }
}
