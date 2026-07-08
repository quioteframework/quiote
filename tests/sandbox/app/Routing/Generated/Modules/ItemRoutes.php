<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Item (7 routes; built 2025-08-18T17:53:05+00:00)
 */
final class ItemRoutes {
    /**
     * @param array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}> $meta
     */
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=item raw_path=/item gen=/item module=Item action=Index
        $routes->add('item', new Route('/item', [
    '_module' => 'Item',
    '_action' => 'Index',
], []));
        $meta['item'] = [
    'gen_path' => '/item',
    'cut' => false,
    'path' => '/item',
];
        // DEBUG: name=item.attachments raw_path=/item/{item_id}/attachments/{file_id} gen=/item/{item_id}/attachments/{file_id} module=Item action=Index.Attachments
        $routes->add('item.attachments', new Route('/item/{item_id}/attachments/{file_id}', [
    '_module' => 'Item',
    '_action' => 'Index.Attachments',
], [
    'file_id' => '\\d+',
    'item_id' => '\\d+',
]));
        $meta['item.attachments'] = [
    'gen_path' => '/item/{item_id}/attachments/{file_id}',
    'cut' => false,
    'path' => '/item/{item_id}/attachments/{file_id}',
];
        // DEBUG: name=item.comment raw_path=/item/{item_id}/comment/{comment_id} gen=/item/{item_id}/comment/{comment_id} module=Item action=Index.Comment
        $routes->add('item.comment', new Route('/item/{item_id}/comment/{comment_id}', [
    '_module' => 'Item',
    '_action' => 'Index.Comment',
], [
    'comment_id' => '\\d+',
    'item_id' => '\\d+',
]));
        $meta['item.comment'] = [
    'gen_path' => '/item/{item_id}/comment/{comment_id}',
    'cut' => false,
    'path' => '/item/{item_id}/comment/{comment_id}',
];
        // DEBUG: name=item.edit raw_path=/item/{item_id}/edit gen=/item/{item_id}/edit module=Item action=Index.Edit
        $routes->add('item.edit', new Route('/item/{item_id}/edit', [
    '_module' => 'Item',
    '_action' => 'Index.Edit',
], [
    'item_id' => '\\d+',
]));
        $meta['item.edit'] = [
    'gen_path' => '/item/{item_id}/edit',
    'cut' => false,
    'path' => '/item/{item_id}/edit',
];
        // DEBUG: name=item.tasks raw_path=/item/{item_id}/tasks gen=/item/{item_id}/tasks module=Item action=Index.Tasks
        $routes->add('item.tasks', new Route('/item/{item_id}/tasks', [
    '_module' => 'Item',
    '_action' => 'Index.Tasks',
], [
    'item_id' => '\\d+',
]));
        $meta['item.tasks'] = [
    'gen_path' => '/item/{item_id}/tasks',
    'cut' => false,
    'path' => '/item/{item_id}/tasks',
];
        // DEBUG: name=item.tasks.edit raw_path=/item/{item_id}/tasks/{task_id}/edit gen=/item/{item_id}/tasks/{task_id}/edit module=Item action=Index.TaskEdit
        $routes->add('item.tasks.edit', new Route('/item/{item_id}/tasks/{task_id}/edit', [
    '_module' => 'Item',
    '_action' => 'Index.TaskEdit',
], [
    'item_id' => '\\d+',
    'task_id' => '\\d+',
]));
        $meta['item.tasks.edit'] = [
    'gen_path' => '/item/{item_id}/tasks/{task_id}/edit',
    'cut' => false,
    'path' => '/item/{item_id}/tasks/{task_id}/edit',
];
        // DEBUG: name=item.view raw_path=/item/{item_id} gen=/item/{item_id} module=Item action=Index.View
        $routes->add('item.view', new Route('/item/{item_id}', [
    '_module' => 'Item',
    '_action' => 'Index.View',
], [
    'item_id' => '\\d+',
]));
        $meta['item.view'] = [
    'gen_path' => '/item/{item_id}',
    'cut' => false,
    'path' => '/item/{item_id}',
];
    }
}
