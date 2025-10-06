<?php
use PHPUnit\Framework\TestCase;
use Agavi\Middleware\MiddlewareCatalog;
use Agavi\Middleware\ExecutionTimeMiddleware;

class MiddlewareConfigToggleTest extends TestCase
{
    public function testExecutionTimeMiddlewareDisabledByConfigMap()
    {
        // Simulate config map disabling ExecutionTimeMiddleware
        MiddlewareCatalog::initialize([
            ExecutionTimeMiddleware::class => false,
        ]);
        $this->assertFalse(MiddlewareCatalog::isEnabled(ExecutionTimeMiddleware::class));
    }
}
