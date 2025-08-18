<?php
namespace Agavi\Routing;

/**
 * Immutable routing result facade providing legacy-like getters.
 */
final class RoutingResult
{
    public function __construct(
    private ?string $module,
    private ?string $action,
        private string $outputType,
        private string $method,
        private array $parameters = [],
    private array $matchedRoutes = []
    ) {}

    public function getModuleName(): ?string { return $this->module; }
    public function getActionName(): ?string { return $this->action; }
    public function getOutputType(): string { return $this->outputType; }
    public function getRequestMethod(): string { return $this->method; }
    public function getParameters(): array { return $this->parameters; }
    public function getMatchedRoutes(): array { return $this->matchedRoutes; }
}
