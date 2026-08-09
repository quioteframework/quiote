<?php

namespace Quiote\Logging;

use Psr\Log\LoggerInterface;

/**
 * DI-injectable factory for category loggers. Delegates to the same
 * {@see LogRegistry} the {@see Log} facade uses, so injected and facade loggers
 * share one configuration.
 */
interface LoggerFactoryInterface
{
    /**
     * Returns a logger bound to the given category name verbatim.
     *
     * Implementations must return a logger whose threshold and sinks come from
     * the same configuration the {@see Log} facade uses, so an injected logger
     * and a facade logger for the same category behave identically.
     */
    public function create(string $category): LoggerInterface;

    /**
     * Returns a logger whose category is derived from a class name or instance.
     *
     * Accepts either the object itself or its fully-qualified class name;
     * implementations must map both to the same category.
     */
    public function for(object|string $classOrObject): LoggerInterface;
}
