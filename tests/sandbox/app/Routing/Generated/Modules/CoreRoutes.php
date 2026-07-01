<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Core (2 routes; built 2025-08-18T17:53:05+00:00)
 */
final class CoreRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=health raw_path=/healthz gen=/healthz module=Core action=Health
        $routes->add('health', new Route('/healthz', [
    '_module' => 'Core',
    '_action' => 'Health',
], []));
        $meta['health'] = [
    'gen_path' => '/healthz',
    'cut' => false,
    'path' => '/healthz',
];
        // DEBUG: name=root raw_path=/ gen=/ module=Core action=Home
        $routes->add('root', new Route('/', [
    '_module' => 'Core',
    '_action' => 'Home',
], []));
        $meta['root'] = [
    'gen_path' => '/',
    'cut' => false,
    'path' => '/',
];
    }
}
