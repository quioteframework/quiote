<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Report (2 routes; built 2025-08-18T17:53:05+00:00)
 */
final class ReportRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=report raw_path=/reports gen=/reports module=Report action=Index
        $routes->add('report', new Route('/reports', [
    '_module' => 'Report',
    '_action' => 'Index',
], []));
        $meta['report'] = [
    'gen_path' => '/reports',
    'cut' => false,
    'path' => '/reports',
];
        // DEBUG: name=report.export raw_path=/reports/export/{format} gen=/reports/export/{format} module=Report action=Index.Export
        $routes->add('report.export', new Route('/reports/export/{format}', [
    '_module' => 'Report',
    '_action' => 'Index.Export',
], [
    'format' => 'csv|xls',
]));
        $meta['report.export'] = [
    'gen_path' => '/reports/export/{format}',
    'cut' => false,
    'path' => '/reports/export/{format}',
];
    }
}
