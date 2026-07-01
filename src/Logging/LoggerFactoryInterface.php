<?php

namespace Agavi\Logging;

use Psr\Log\LoggerInterface;

/**
 * DI-injectable factory for category loggers. Delegates to the same
 * {@see LogRegistry} the {@see Log} facade uses, so injected and facade loggers
 * share one configuration.
 */
interface LoggerFactoryInterface
{
    public function create(string $category): LoggerInterface;

    public function for(object|string $classOrObject): LoggerInterface;
}
