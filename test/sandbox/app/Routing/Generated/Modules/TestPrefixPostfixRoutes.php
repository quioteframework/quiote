<?php
declare(strict_types=1);

namespace Sandbox\App\Routing\Generated\Modules;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes for module TestPrefixPostfix (2 routes; built 2025-08-18T17:53:05+00:00)
 */
final class TestPrefixPostfixRoutes {
    public static function addRoutes(RouteCollection $routes, array &$meta): void {
        // DEBUG: name=with_prefix_and_postfix raw_path=/with_prefix_and_postfix/{param:.*} gen=/with_prefix_and_postfix/{param:.*} module=TestPrefixPostfix action=Matched
        $routes->add('with_prefix_and_postfix', new Route('/with_prefix_and_postfix/{param:.*}', [
    '_module' => 'TestPrefixPostfix',
    '_action' => 'Matched',
], []));
        $meta['with_prefix_and_postfix'] = [
    'gen_path' => '/with_prefix_and_postfix/{param:.*}',
    'cut' => false,
    'path' => '/with_prefix_and_postfix/{param:.*}',
];
        // DEBUG: name=with_prefix_and_postfix_auto_detected raw_path=/with_prefix_and_postfix/myprefix/{param:.*}/my-postfix gen=/with_prefix_and_postfix/myprefix/{param:.*}/my-postfix module=TestPrefixPostfix action=Matched
        $routes->add('with_prefix_and_postfix_auto_detected', new Route('/with_prefix_and_postfix/myprefix/{param:.*}/my-postfix', [
    '_module' => 'TestPrefixPostfix',
    '_action' => 'Matched',
], []));
        $meta['with_prefix_and_postfix_auto_detected'] = [
    'gen_path' => '/with_prefix_and_postfix/myprefix/{param:.*}/my-postfix',
    'cut' => false,
    'path' => '/with_prefix_and_postfix/myprefix/{param:.*}/my-postfix',
];
    }
}
