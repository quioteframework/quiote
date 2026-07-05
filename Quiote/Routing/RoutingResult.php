<?php
namespace Quiote\Routing;

/**
 * Immutable routing result facade providing legacy-like getters.
 */
final readonly class RoutingResult
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<int, mixed> $matchedRoutes
     */
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

    /** @return array<string, mixed> */
    public function getParameters(): array { return $this->parameters; }

    /** @return array<int, mixed> */
    public function getMatchedRoutes(): array { return $this->matchedRoutes; }
}
