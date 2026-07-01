<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module File (3 routes; built 2025-08-18T17:53:05+00:00)
 */
final class FileRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=files raw_path=/files gen=/files module=File action=Index
        $routes->add('files', new Route('/files', [
    '_module' => 'File',
    '_action' => 'Index',
], []));
        $meta['files'] = [
    'gen_path' => '/files',
    'cut' => false,
    'path' => '/files',
];
        // DEBUG: name=files.download raw_path=/files/{file_id}/download gen=/files/{file_id}/download module=File action=Index.Download
        $routes->add('files.download', new Route('/files/{file_id}/download', [
    '_module' => 'File',
    '_action' => 'Index.Download',
], [
    'file_id' => '\\d+',
]));
        $meta['files.download'] = [
    'gen_path' => '/files/{file_id}/download',
    'cut' => false,
    'path' => '/files/{file_id}/download',
];
        // DEBUG: name=files.preview raw_path=/files/{file_id}/preview gen=/files/{file_id}/preview module=File action=Index.Preview
        $routes->add('files.preview', new Route('/files/{file_id}/preview', [
    '_module' => 'File',
    '_action' => 'Index.Preview',
], [
    'file_id' => '\\d+',
]));
        $meta['files.preview'] = [
    'gen_path' => '/files/{file_id}/preview',
    'cut' => false,
    'path' => '/files/{file_id}/preview',
];
    }
}
