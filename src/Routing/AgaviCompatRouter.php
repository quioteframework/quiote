<?php
declare(strict_types=1);

namespace Agavi\Routing;

/**
 * @deprecated Legacy alias kept temporarily; prefer AgaviRouting subclass (e.g., SandboxRouting / AgaviWebRouting).
 * This class will be removed once all factory configs updated.
 */
final class AgaviCompatRouter extends AgaviRouting
{
    protected function build(): array
    {
        // Provide empty route collection to avoid errors if accidentally instantiated.
        return [new \Symfony\Component\Routing\RouteCollection(), []];
    }
}
