<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Quiote\Context;

/**
 * The complete state of {@see MiddlewareCatalog} at one moment, taken by
 * {@see MiddlewareCatalog::snapshot()} and put back by
 * {@see MiddlewareCatalog::restore()}. Opaque to everything else.
 */
final readonly class MiddlewareCatalogSnapshot
{
    /**
     * @param array<string,bool> $enabledMap
     * @param array<string,array{fqcn: string, factory: callable, after: ?string, before: ?string, priority: int}> $registered
     * @param array<string,array{fqcn: string, factory: callable, after: ?string, before: ?string, priority: int}> $pluginRegistered
     * @param array<string,true|callable(Context): MiddlewareInterface> $attributedCandidates
     * @param array<string,true|callable(Context): MiddlewareInterface> $pluginAttributedCandidates
     * @param (\Closure(Context): list<MiddlewareInterface>)|null $coreStackFactory
     */
    public function __construct(
        public array $enabledMap,
        public array $registered,
        public array $pluginRegistered,
        public array $attributedCandidates,
        public array $pluginAttributedCandidates,
        public ?\Closure $coreStackFactory,
    ) {}
}
