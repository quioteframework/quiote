<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module Admin (6 routes; built 2025-08-18T17:53:05+00:00)
 */
final class AdminRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=admin raw_path=/admin gen=/admin module=Admin action=Index
        $routes->add('admin', new Route('/admin', [
    '_module' => 'Admin',
    '_action' => 'Index',
], []));
        $meta['admin'] = [
    'gen_path' => '/admin',
    'cut' => false,
    'path' => '/admin',
];
        // DEBUG: name=admin.companies raw_path=/admin/companies gen=/admin/companies module=Admin action=Index.Companies
        $routes->add('admin.companies', new Route('/admin/companies', [
    '_module' => 'Admin',
    '_action' => 'Index.Companies',
], []));
        $meta['admin.companies'] = [
    'gen_path' => '/admin/companies',
    'cut' => false,
    'path' => '/admin/companies',
];
        // DEBUG: name=admin.companies.view raw_path=/admin/company/{company_id} gen=/admin/company/{company_id} module=Admin action=Index.Company
        $routes->add('admin.companies.view', new Route('/admin/company/{company_id}', [
    '_module' => 'Admin',
    '_action' => 'Index.Company',
], [
    'company_id' => '\\d+',
]));
        $meta['admin.companies.view'] = [
    'gen_path' => '/admin/company/{company_id}',
    'cut' => false,
    'path' => '/admin/company/{company_id}',
];
        // DEBUG: name=admin.company.edit raw_path=/admin/company/{company_id}/edit gen=/admin/company/{company_id}/edit module=Admin action=Index.CompanyEdit
        $routes->add('admin.company.edit', new Route('/admin/company/{company_id}/edit', [
    '_module' => 'Admin',
    '_action' => 'Index.CompanyEdit',
], [
    'company_id' => '\\d+',
]));
        $meta['admin.company.edit'] = [
    'gen_path' => '/admin/company/{company_id}/edit',
    'cut' => false,
    'path' => '/admin/company/{company_id}/edit',
];
        // DEBUG: name=admin.company.stats raw_path=/admin/company/{company_id}/stats gen=/admin/company/{company_id}/stats module=Admin action=Index.CompanyStats
        $routes->add('admin.company.stats', new Route('/admin/company/{company_id}/stats', [
    '_module' => 'Admin',
    '_action' => 'Index.CompanyStats',
], [
    'company_id' => '\\d+',
]));
        $meta['admin.company.stats'] = [
    'gen_path' => '/admin/company/{company_id}/stats',
    'cut' => false,
    'path' => '/admin/company/{company_id}/stats',
];
        // DEBUG: name=admin.reports raw_path=/admin/reports/{year} gen=/admin/reports/{year} module=Admin action=Index.Reports
        // NOTE: originally generated as the invalid, unconverted inline capture
        // "{year:\d{4}}?" -- "{name:regex}" was never valid Symfony route syntax
        // (only "{name}" plus a separate requirement is), and the inner "\d{4}"
        // quantifier's own braces broke UrlMatcher's compilation outright. Hand-fixed
        // to a placeholder + requirement, with a default so the trailing segment
        // stays optional (matches both "/admin/reports" and "/admin/reports/2024").
        $routes->add('admin.reports', new Route('/admin/reports/{year}', [
    '_module' => 'Admin',
    '_action' => 'Index.Reports',
    'year' => '',
], [
    'year' => '\\d{4}',
]));
        $meta['admin.reports'] = [
    'gen_path' => '/admin/reports/{year}',
    'cut' => false,
    'path' => '/admin/reports/{year}',
];
    }
}
