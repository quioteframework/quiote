<?php

namespace Quiote\Mcp\Bridge;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Quiote\DI\Container;

/**
 * Wraps Quiote's DI {@see Container} as the PSR-11 container `mcp/sdk` uses
 * (`Mcp\Server\Builder::setContainer()`) to resolve string/array tool
 * handlers (`Mcp\Capability\Registry\ReferenceHandler::getClassInstance()`).
 *
 * Quiote's own `Container::has()` deliberately reflects only explicit
 * registrations/aliases, not autowireable classes (see its docblock) — so
 * ordinary callers can't observe autowiring through a PSR-11 `has()` check.
 * But MCP tool/resource/prompt handler classes are typically plain
 * autowireable classes that are never explicitly bound. If `has()` returned
 * false for those, `ReferenceHandler` falls back to `new $class()`,
 * bypassing DI (and any constructor dependencies) entirely. This adapter
 * reports any loadable class as present so `get()`'s autowiring path
 * resolves it instead.
 */
final class ContainerAdapter implements PsrContainerInterface
{
    public function __construct(private readonly Container $container) {}

    public function has(string $id): bool
    {
        return $this->container->has($id) || class_exists($id);
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }
}
