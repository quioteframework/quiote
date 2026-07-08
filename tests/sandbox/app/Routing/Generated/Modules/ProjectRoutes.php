<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Project (4 routes; built 2025-08-18T17:53:05+00:00)
 */
final class ProjectRoutes {
    /**
     * @param array<string, array{gen_path: string, cut: bool, path: string, opt?: array{parent: string|null, action: mixed}, pattern?: string, match_full?: string, match_partial?: string}> $meta
     */
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=project raw_path=/projects gen=/projects module=Project action=Index
        $routes->add('project', new Route('/projects', [
    '_module' => 'Project',
    '_action' => 'Index',
], []));
        $meta['project'] = [
    'gen_path' => '/projects',
    'cut' => false,
    'path' => '/projects',
];
        // DEBUG: name=project.new raw_path=/projects/new/{template_id} gen=/projects/new/{template_id} module=Project action=Index.New
        $routes->add('project.new', new Route('/projects/new/{template_id}', [
    '_module' => 'Project',
    '_action' => 'Index.New',
], [
    'template_id' => '\\d+',
]));
        $meta['project.new'] = [
    'gen_path' => '/projects/new/{template_id}',
    'cut' => false,
    'path' => '/projects/new/{template_id}',
];
        // DEBUG: name=project.stats raw_path=/projects/{project_id}/stats gen=/projects/{project_id}/stats module=Project action=Index.Stats
        $routes->add('project.stats', new Route('/projects/{project_id}/stats', [
    '_module' => 'Project',
    '_action' => 'Index.Stats',
], [
    'project_id' => '\\d+',
]));
        $meta['project.stats'] = [
    'gen_path' => '/projects/{project_id}/stats',
    'cut' => false,
    'path' => '/projects/{project_id}/stats',
];
        // DEBUG: name=project.view raw_path=/projects/{project_id} gen=/projects/{project_id} module=Project action=Index.View
        $routes->add('project.view', new Route('/projects/{project_id}', [
    '_module' => 'Project',
    '_action' => 'Index.View',
], [
    'project_id' => '\\d+',
]));
        $meta['project.view'] = [
    'gen_path' => '/projects/{project_id}',
    'cut' => false,
    'path' => '/projects/{project_id}',
];
    }
}
