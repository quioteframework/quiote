<?php
declare(strict_types=1);

namespace Sandbox\App\Routing;

use Quiote\Routing\Routing;
use Sandbox\App\Routing\Generated\Routes;
use Symfony\Component\Routing\RouteCollection;

/**
 * Application routing implementation built from generated PHP route files.
 * Generated once via generate_symfony_routes.php and then committed.
 */
final class SandboxRouting extends Routing
{
    protected function build(): array
    {
        return Routes::build(); // [RouteCollection, meta]
    }

    /**
     * Expose the built route collection and its meta structure so
     * RoutingMiddleware can wire them up for dispatch.
     */
    #[\Override]
    public function exportRoutes(): array
    {
        return [$this->getRouteCollection(), $this->getMeta()];
    }
}
