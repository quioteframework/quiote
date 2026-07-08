<?php
declare(strict_types=1);

namespace QuioteTestGeneratedTest2\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module t1Module1 (1 routes; built 2025-08-18T12:51:47+00:00)
 */
final class t1Module1Routes {
    /**
     * @param array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}> $meta
     */
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
    $routes->add('test2child1', new Route('/parent/{category}/{machine}/', [
    '_module' => 't1Module1',
    '_action' => 't2Action1',
], []));
        $meta['test2child1'] = [
    'gen_path' => '/parent/{category}/{machine}',
    'cut' => false,
    'path' => '/parent/{category}/{machine}/',
];
    }
}
