<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module TestWithParam (2 routes; built 2025-08-18T17:53:05+00:00)
 */
final class TestWithParamRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=with_param raw_path=/withparam/{number} gen=/withparam/{number} module=TestWithParam action=MatchedParam
        $routes->add('with_param', new Route('/withparam/{number}', [
    '_module' => 'TestWithParam',
    '_action' => 'MatchedParam',
], [
    'number' => '\\d+',
]));
        $meta['with_param'] = [
    'gen_path' => '/withparam/{number}',
    'cut' => false,
    'path' => '/withparam/{number}',
];
        // DEBUG: name=with_two_params raw_path=/withmultipleparams/{number}/{string} gen=/withmultipleparams/{number}/{string} module=TestWithParam action=MatchedMultipleParams
        $routes->add('with_two_params', new Route('/withmultipleparams/{number}/{string}', [
    '_module' => 'TestWithParam',
    '_action' => 'MatchedMultipleParams',
], [
    'number' => '\\d+',
    'string' => '\\w+',
]));
        $meta['with_two_params'] = [
    'gen_path' => '/withmultipleparams/{number}/{string}',
    'cut' => false,
    'path' => '/withmultipleparams/{number}/{string}',
];
    }
}
