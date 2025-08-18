<?php
declare(strict_types=1);

namespace Sandbox\App\Routing;

use Agavi\Routing\AgaviRouting;
use Sandbox\App\Routing\Generated\Routes;
use Symfony\Component\Routing\RouteCollection;

/**
 * Application routing implementation built from generated PHP route files.
 * Generated once via generate_symfony_routes.php and then committed.
 */
final class SandboxRouting extends AgaviRouting
{
    protected function build(): array
    {
        return Routes::build(); // [RouteCollection, meta]
    }
}
