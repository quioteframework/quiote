<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Tag (4 routes; built 2025-08-18T17:53:05+00:00)
 */
final class TagRoutes {
    /**
     * @param array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}> $meta
     */
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=tag raw_path=/tags gen=/tags module=Tag action=Index
        $routes->add('tag', new Route('/tags', [
    '_module' => 'Tag',
    '_action' => 'Index',
], []));
        $meta['tag'] = [
    'gen_path' => '/tags',
    'cut' => false,
    'path' => '/tags',
];
        // DEBUG: name=tag.add raw_path=/tags/new gen=/tags/new module=Tag action=Index.Add
        $routes->add('tag.add', new Route('/tags/new', [
    '_module' => 'Tag',
    '_action' => 'Index.Add',
], []));
        $meta['tag.add'] = [
    'gen_path' => '/tags/new',
    'cut' => false,
    'path' => '/tags/new',
];
        // DEBUG: name=tag.list raw_path=/tags/list gen=/tags/list module=Tag action=Index.List
        $routes->add('tag.list', new Route('/tags/list', [
    '_module' => 'Tag',
    '_action' => 'Index.List',
], []));
        $meta['tag.list'] = [
    'gen_path' => '/tags/list',
    'cut' => false,
    'path' => '/tags/list',
];
        // DEBUG: name=tag.remove raw_path=/tags/{tag_id} gen=/tags/{tag_id} module=Tag action=Index.Remove
        $routes->add('tag.remove', new Route('/tags/{tag_id}', [
    '_module' => 'Tag',
    '_action' => 'Index.Remove',
], [
    'tag_id' => '\\d+',
]));
        $meta['tag.remove'] = [
    'gen_path' => '/tags/{tag_id}',
    'cut' => false,
    'path' => '/tags/{tag_id}',
];
    }
}
