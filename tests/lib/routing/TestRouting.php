<?php

namespace Quiote\Test\Routing;

use Quiote\Routing\Routing;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

/**
 * Minimal concrete routing implementation for framework-level tests.
 * Provides a single fixed route and allows test code to add more via addRoute().
 */
class TestRouting extends Routing
{
    protected function build(): array
    {
        $rc = new RouteCollection();
        // Provide at least one route so matcher is functional
        $rc->add('root', new Route('/', ['module' => 'Index', 'action' => 'Show']));
        $meta = [
            'root' => [
                'gen_path' => '/',
                'cut' => false,
                'path' => '/',
                'pattern' => '/',
                'match_full' => '#^/$#',
                'match_partial' => '#^/#',
                'opt' => ['parent' => null, 'action' => 'Show'],
            ],
        ];
        return [$rc, $meta];
    }
}
