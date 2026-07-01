<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Integration (3 routes; built 2025-08-18T17:53:05+00:00)
 */
final class IntegrationRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=integration raw_path=/integrate gen=/integrate module=Integration action=Index
        $routes->add('integration', new Route('/integrate', [
    '_module' => 'Integration',
    '_action' => 'Index',
], []));
        $meta['integration'] = [
    'gen_path' => '/integrate',
    'cut' => false,
    'path' => '/integrate',
];
        // DEBUG: name=integration.resource raw_path=/integrate/{integration_id}/{resource_id} gen=/integrate/{integration_id}/{resource_id} module=Integration action=Index.Resource
        $routes->add('integration.resource', new Route('/integrate/{integration_id}/{resource_id}', [
    '_module' => 'Integration',
    '_action' => 'Index.Resource',
], [
    'integration_id' => '\\d+',
    'resource_id' => '\\w+',
]));
        $meta['integration.resource'] = [
    'gen_path' => '/integrate/{integration_id}/{resource_id}',
    'cut' => false,
    'path' => '/integrate/{integration_id}/{resource_id}',
];
        // DEBUG: name=integration.resource.tmp raw_path=/integrate/tmp/{token} gen=/integrate/tmp/{token} module=Integration action=Index.ResourceTmp
        $routes->add('integration.resource.tmp', new Route('/integrate/tmp/{token}', [
    '_module' => 'Integration',
    '_action' => 'Index.ResourceTmp',
], [
    'token' => '\\w+',
]));
        $meta['integration.resource.tmp'] = [
    'gen_path' => '/integrate/tmp/{token}',
    'cut' => false,
    'path' => '/integrate/tmp/{token}',
];
    }
}
