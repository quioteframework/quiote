<?php

namespace Agavi\Logging;

use Psr\Log\LoggerInterface;

/**
 * Default {@see LoggerFactoryInterface}: thin wrapper over the {@see Log} facade
 * (and thus {@see LogRegistry}) for constructor injection via the DI container.
 */
final class LoggerFactory implements LoggerFactoryInterface
{
    public function create(string $category): LoggerInterface
    {
        return Log::create($category);
    }

    public function for(object|string $classOrObject): LoggerInterface
    {
        return Log::for($classOrObject);
    }
}
