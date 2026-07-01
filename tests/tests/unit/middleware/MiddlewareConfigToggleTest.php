<?php
use PHPUnit\Framework\TestCase;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\ExecutionTimeMiddleware;

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
