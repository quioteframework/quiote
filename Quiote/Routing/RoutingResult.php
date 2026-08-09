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

    /** Returns the matched module name, or null when routing did not resolve one. */
    public function getModuleName(): ?string { return $this->module; }

    /** Returns the matched action name, or null when routing did not resolve one. */
    public function getActionName(): ?string { return $this->action; }

    /** Returns the output type the matched route renders with. */
    public function getOutputType(): string { return $this->outputType; }

    /** Returns the HTTP method the routed request was made with. */
    public function getRequestMethod(): string { return $this->method; }

    /** @return array<string, mixed> */
    public function getParameters(): array { return $this->parameters; }

    /** @return array<int, mixed> */
    public function getMatchedRoutes(): array { return $this->matchedRoutes; }
}
