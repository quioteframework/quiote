<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Portal (3 routes; built 2025-08-18T17:53:05+00:00)
 */
final class PortalRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=test_ticket_437 raw_path=/test_ticket_437/{default} gen=/test_ticket_437/{default} module=Portal action=Index
        $routes->add('test_ticket_437', new Route('/test_ticket_437/{default}', [
    '_module' => 'Portal',
    '_action' => 'Index',
], [
    'default' => '\\d+',
]));
        $meta['test_ticket_437'] = [
    'gen_path' => '/test_ticket_437/{default}',
    'cut' => false,
    'path' => '/test_ticket_437/{default}',
];
        // DEBUG: name=test_ticket_464 raw_path=/test_ticket_464/{type}/{page} gen=/test_ticket_464/{type}/{page} module=Portal action=Index
        $routes->add('test_ticket_464', new Route('/test_ticket_464/{type}/{page}', [
    '_module' => 'Portal',
    '_action' => 'Index',
], [
    'type' => '[^/]+',
    'page' => '\\d+',
]));
        $meta['test_ticket_464'] = [
    'gen_path' => '/test_ticket_464/{type}/{page}',
    'cut' => false,
    'path' => '/test_ticket_464/{type}/{page}',
];
        // DEBUG: name=test_ticket_698 raw_path=/test_ticket_698/{overwritten_by_callback} gen=/test_ticket_698/{overwritten_by_callback} module=Portal action=Index
        $routes->add('test_ticket_698', new Route('/test_ticket_698/{overwritten_by_callback}', [
    '_module' => 'Portal',
    '_action' => 'Index',
], [
    'overwritten_by_callback' => '\\w+',
]));
        $meta['test_ticket_698'] = [
    'gen_path' => '/test_ticket_698/{overwritten_by_callback}',
    'cut' => false,
    'path' => '/test_ticket_698/{overwritten_by_callback}',
];
    }
}
