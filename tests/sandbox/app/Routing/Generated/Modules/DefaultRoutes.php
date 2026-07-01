<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Default (4 routes; built 2025-08-18T17:53:05+00:00)
 */
final class DefaultRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=index raw_path=/ gen=/ module=%actions.default_module% action=%actions.default_action%
        $routes->add('index', new Route('/', [
    '_module' => 'Default',
    '_action' => 'Index',
], []));
        $meta['index'] = [
    'gen_path' => '/',
    'cut' => false,
    'path' => '/',
];
        // DEBUG: name=test_ticket_713 raw_path=/test_ticket_713/{zomg} gen=/test_ticket_713/{zomg} module=Default action=Index
        $routes->add('test_ticket_713', new Route('/test_ticket_713/{zomg}', [
    '_module' => 'Default',
    '_action' => 'Index',
], [
    'zomg' => 'zomg|lol',
]));
        $meta['test_ticket_713'] = [
    'gen_path' => '/test_ticket_713/{zomg}',
    'cut' => false,
    'path' => '/test_ticket_713/{zomg}',
];
        // DEBUG: name=test_ticket_764 raw_path=/test_ticket_764 gen=/test_ticket_764 module=Default action=Foo
        $routes->add('test_ticket_764', new Route('/test_ticket_764', [
    '_module' => 'Default',
    '_action' => 'Foo',
], []));
        $meta['test_ticket_764'] = [
    'gen_path' => '/test_ticket_764',
    'cut' => false,
    'path' => '/test_ticket_764',
];
        // DEBUG: name=test_ticket_764.unnamed_0ade7c2c.child raw_path=/test_ticket_764/dummy/child gen=/test_ticket_764/dummy/child module=Default action=Foo.Bar
        $routes->add('test_ticket_764.unnamed_0ade7c2c.child', new Route('/test_ticket_764/dummy/child', [
    '_module' => 'Default',
    '_action' => 'Foo.Bar',
], []));
        $meta['test_ticket_764.unnamed_0ade7c2c.child'] = [
    'gen_path' => '/test_ticket_764/dummy/child',
    'cut' => false,
    'path' => '/test_ticket_764/dummy/child',
];
    }
}
