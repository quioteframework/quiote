<?php

use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Mcp\Bridge\ContainerAdapter;

final class McpBridgeFixtureService
{
    public string $greeting = 'hi';
}

/**
 * {@see ContainerAdapter} deliberately widens `has()` beyond Quiote's own
 * strict "explicit registrations only" PSR-11 contract, so that mcp/sdk's
 * ReferenceHandler routes plain autowireable handler classes through DI
 * (get()'s autowiring) instead of falling back to `new $class()`. See the
 * adapter's docblock for the full rationale.
 */
final class ContainerAdapterTest extends TestCase
{
    public function testHasIsTrueForAnAutowireableClassNotExplicitlyRegistered(): void
    {
        $adapter = new ContainerAdapter(new Container());

        $this->assertFalse((new Container())->has(McpBridgeFixtureService::class), 'sanity: the raw container does not consider it registered');
        $this->assertTrue($adapter->has(McpBridgeFixtureService::class));
    }

    public function testHasIsFalseForAnUnknownClass(): void
    {
        $adapter = new ContainerAdapter(new Container());

        $this->assertFalse($adapter->has('App\\Does\\Not\\Exist'));
    }

    public function testGetAutowiresAnUnregisteredClass(): void
    {
        $adapter = new ContainerAdapter(new Container());

        $instance = $adapter->get(McpBridgeFixtureService::class);

        $this->assertInstanceOf(McpBridgeFixtureService::class, $instance);
        $this->assertSame('hi', $instance->greeting);
    }

    public function testGetReturnsAnExplicitlyBoundInstance(): void
    {
        $container = new Container();
        $bound = new McpBridgeFixtureService();
        $bound->greeting = 'bound';
        $container->set(McpBridgeFixtureService::class, $bound);

        $adapter = new ContainerAdapter($container);

        $this->assertSame($bound, $adapter->get(McpBridgeFixtureService::class));
    }
}
