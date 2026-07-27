<?php

use PHPUnit\Framework\TestCase;
use Quiote\Http\Psr17;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Psr17Factory is stateless; Psr17::factory() must hand out a single shared
 * instance instead of every hot-path call site (DispatchMiddleware,
 * ValidationMiddleware, SecurityMiddleware, PayloadParsingMiddleware,
 * FormPopulationMiddleware) allocating its own.
 */
class Psr17Test extends TestCase
{
    public function testFactoryReturnsSameInstanceAcrossCalls(): void
    {
        $first = Psr17::factory();
        $second = Psr17::factory();

        $this->assertInstanceOf(Psr17Factory::class, $first);
        $this->assertSame($first, $second);
    }

    public function testFactoryProducesUsablePsr7Objects(): void
    {
        $factory = Psr17::factory();
        $response = $factory->createResponse(204);
        $stream = $factory->createStream('hello');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('hello', (string) $stream);
    }
}
