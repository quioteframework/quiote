<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Auth (4 routes; built 2025-08-18T17:53:05+00:00)
 */
final class AuthRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=auth raw_path=/auth gen=/auth module=Auth action=Index
        $routes->add('auth', new Route('/auth', [
    '_module' => 'Auth',
    '_action' => 'Index',
], []));
        $meta['auth'] = [
    'gen_path' => '/auth',
    'cut' => false,
    'path' => '/auth',
];
        // DEBUG: name=auth.login raw_path=/auth/login/{method} gen=/auth/login/{method} module=Auth action=Index.Login
        $routes->add('auth.login', new Route('/auth/login/{method}', [
    '_module' => 'Auth',
    '_action' => 'Index.Login',
], [
    'method' => '\\w+',
]));
        $meta['auth.login'] = [
    'gen_path' => '/auth/login/{method}',
    'cut' => false,
    'path' => '/auth/login/{method}',
];
        // DEBUG: name=auth.logout raw_path=/auth/logout gen=/auth/logout module=Auth action=Index.Logout
        $routes->add('auth.logout', new Route('/auth/logout', [
    '_module' => 'Auth',
    '_action' => 'Index.Logout',
], []));
        $meta['auth.logout'] = [
    'gen_path' => '/auth/logout',
    'cut' => false,
    'path' => '/auth/logout',
];
        // DEBUG: name=auth.token raw_path=/auth/token gen=/auth/token module=Auth action=Index.Token
        $routes->add('auth.token', new Route('/auth/token', [
    '_module' => 'Auth',
    '_action' => 'Index.Token',
], []));
        $meta['auth.token'] = [
    'gen_path' => '/auth/token',
    'cut' => false,
    'path' => '/auth/token',
];
    }
}
