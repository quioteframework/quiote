<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module User (5 routes; built 2025-08-18T17:53:05+00:00)
 */
final class UserRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=user raw_path=/user gen=/user module=User action=Index
        $routes->add('user', new Route('/user', [
    '_module' => 'User',
    '_action' => 'Index',
], []));
        $meta['user'] = [
    'gen_path' => '/user',
    'cut' => false,
    'path' => '/user',
];
        // DEBUG: name=user.avatar raw_path=/user/{user_id}/avatar/{image_id} gen=/user/{user_id}/avatar/{image_id} module=User action=Index.Avatar
        $routes->add('user.avatar', new Route('/user/{user_id}/avatar/{image_id}', [
    '_module' => 'User',
    '_action' => 'Index.Avatar',
], [
    'image_id' => '\\d+',
    'user_id' => '\\d+',
]));
        $meta['user.avatar'] = [
    'gen_path' => '/user/{user_id}/avatar/{image_id}',
    'cut' => false,
    'path' => '/user/{user_id}/avatar/{image_id}',
];
        // DEBUG: name=user.profile raw_path=/user/{user_id} gen=/user/{user_id} module=User action=Index.Profile
        $routes->add('user.profile', new Route('/user/{user_id}', [
    '_module' => 'User',
    '_action' => 'Index.Profile',
], [
    'user_id' => '\\d+',
]));
        $meta['user.profile'] = [
    'gen_path' => '/user/{user_id}',
    'cut' => false,
    'path' => '/user/{user_id}',
];
        // DEBUG: name=user.search raw_path=/user/search/{query} gen=/user/search/{query} module=User action=Index.Search
        $routes->add('user.search', new Route('/user/search/{query}', [
    '_module' => 'User',
    '_action' => 'Index.Search',
], [
    'query' => '\\w+',
]));
        $meta['user.search'] = [
    'gen_path' => '/user/search/{query}',
    'cut' => false,
    'path' => '/user/search/{query}',
];
        // DEBUG: name=user.settings raw_path=/user/{user_id}/settings gen=/user/{user_id}/settings module=User action=Index.Settings
        $routes->add('user.settings', new Route('/user/{user_id}/settings', [
    '_module' => 'User',
    '_action' => 'Index.Settings',
], [
    'user_id' => '\\d+',
]));
        $meta['user.settings'] = [
    'gen_path' => '/user/{user_id}/settings',
    'cut' => false,
    'path' => '/user/{user_id}/settings',
];
    }
}
