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
     * Export current routes + meta structure for legacy config handler compatibility.
     * RoutingConfigHandler expects exportRoutes() returning a spec suitable for importRoutes().
     */
    #[\Override]
    public function exportRoutes(): array
    {
        return [$this->getRouteCollection(), $this->getMeta()];
    }
}
