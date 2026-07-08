<?php
declare(strict_types=1);

namespace QuioteTestGeneratedTest1\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module module4 (1 routes; built 2025-08-18T13:18:24+00:00)
 */
final class Module4Routes {
    /**
     * @param array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}> $meta
     */
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        $routes->add('t1child4', new Route('/anchor/{foo}/{bar}', [
    '_module' => 'module4',
    '_action' => 'action4',
    'bar' => 'baz',
], [
    'foo' => 'child4',
    'bar' => 'nextChild',
]));
        $meta['t1child4'] = [
    'gen_path' => '/anchor/{foo}/{bar}',
    'cut' => false,
    'path' => '/anchor/{foo}/{bar}',
];
    }
}
