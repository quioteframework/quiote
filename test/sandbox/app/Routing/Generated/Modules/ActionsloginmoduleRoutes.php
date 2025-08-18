<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module actions.login_module (1 routes; built 2025-08-18T17:53:05+00:00)
 */
final class ActionsloginmoduleRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=test_ticket_277 raw_path=/test_ticket_277 gen=/test_ticket_277 module=%actions.login_module% action=%actions.login_action%
        $routes->add('test_ticket_277', new Route('/test_ticket_277', [
    '_module' => '%actions.login_module%',
    '_action' => '%actions.login_action%',
    'foo' => 'bar',
], []));
        $meta['test_ticket_277'] = [
    'gen_path' => '/test_ticket_277',
    'cut' => false,
    'path' => '/test_ticket_277',
];
    }
}
