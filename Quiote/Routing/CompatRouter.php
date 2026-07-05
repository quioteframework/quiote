<?php
declare(strict_types=1);

namespace Quiote\Routing;

/**
 * @deprecated Legacy alias kept temporarily; prefer Routing subclass (e.g., SandboxRouting / WebRouting).
 * This class will be removed once all factory configs updated.
 */
final class CompatRouter extends Routing
{
    /** @return array{0: \Symfony\Component\Routing\RouteCollection, 1: array<mixed>} */
    protected function build(): array
    {
        // Provide empty route collection to avoid errors if accidentally instantiated.
        return [new \Symfony\Component\Routing\RouteCollection(), []];
    }
}
