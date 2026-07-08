<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Blog (4 routes; built 2025-08-18T17:53:05+00:00)
 */
final class BlogRoutes {
    /**
     * @param array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}> $meta
     */
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=test_ticket_444_sample2 raw_path=/test_ticket_444_sample2/{name} gen=/test_ticket_444_sample2/{name} module=Blog action=
        $routes->add('test_ticket_444_sample2', new Route('/test_ticket_444_sample2/{name}', [
    '_module' => 'Blog',
], [
    'name' => '[^/]+',
]));
        $meta['test_ticket_444_sample2'] = [
    'gen_path' => '/test_ticket_444_sample2/{name}',
    'cut' => false,
    'path' => '/test_ticket_444_sample2/{name}',
];
        // DEBUG: name=test_ticket_444_sample2.archive raw_path=/test_ticket_444_sample2/{name}/{year}/{month}/{day} gen=/test_ticket_444_sample2/{name}/{year}/{month}/{day} module=Blog action=Archive
        // NOTE: originally generated with the pre-1.0 inline "{year:20\d{2}}" capture
        // syntax left unconverted (invalid Symfony route syntax -- the inner "\d{2}"
        // quantifier's own braces broke whatever one-time script produced this file,
        // which no longer exists in the repo to regenerate from). Hand-fixed to the
        // modern placeholder + requirements + defaults equivalent: year is required
        // to match (no default), month/day are optional trailing segments (a Symfony
        // route variable is optional in matching once it -- and everything after it
        // -- has a default).
        $routes->add('test_ticket_444_sample2.archive', new Route('/test_ticket_444_sample2/{name}/{year}/{month}/{day}', [
    '_module' => 'Blog',
    '_action' => 'Archive',
    'month' => '01',
    'day' => '01',
], [
    'name' => '[^/]+',
    'year' => '20\\d{2}',
    'month' => '\\d{2}',
    'day' => '\\d{2}',
]));
        $meta['test_ticket_444_sample2.archive'] = [
    'gen_path' => '/test_ticket_444_sample2/{name}/{year}/{month}/{day}',
    'cut' => false,
    'path' => '/test_ticket_444_sample2/{name}/{year}/{month}/{day}',
];
        // DEBUG: name=test_ticket_444_sample2.entry raw_path=/test_ticket_444_sample2/{name}/{id}.html gen=/test_ticket_444_sample2/{name}/{id}.html module=Blog action=Entry
        $routes->add('test_ticket_444_sample2.entry', new Route('/test_ticket_444_sample2/{name}/{id}.html', [
    '_module' => 'Blog',
    '_action' => 'Entry',
], [
    'name' => '[^/]+',
    'id' => '\\d+',
]));
        $meta['test_ticket_444_sample2.entry'] = [
    'gen_path' => '/test_ticket_444_sample2/{name}/{id}.html',
    'cut' => false,
    'path' => '/test_ticket_444_sample2/{name}/{id}.html',
];
        // DEBUG: name=test_ticket_444_sample2.index raw_path=/test_ticket_444_sample2/{name} gen=/test_ticket_444_sample2/{name} module=Blog action=Index
        $routes->add('test_ticket_444_sample2.index', new Route('/test_ticket_444_sample2/{name}', [
    '_module' => 'Blog',
    '_action' => 'Index',
], [
    'name' => '[^/]+',
]));
        $meta['test_ticket_444_sample2.index'] = [
    'gen_path' => '/test_ticket_444_sample2/{name}',
    'cut' => false,
    'path' => '/test_ticket_444_sample2/{name}',
];
    }
}
