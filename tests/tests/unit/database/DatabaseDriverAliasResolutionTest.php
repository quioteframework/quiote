<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\DatabaseConfigHandler;
use Quiote\Database\DatabaseDriverRegistry;

/**
 * The config handler must resolve a registered driver alias to its adapter FQCN
 * at compile time, while leaving fully-qualified class names untouched.
 */
class DatabaseDriverAliasResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        DatabaseDriverRegistry::reset();
    }

    public function testAliasResolvesToAdapterFqcnInGeneratedCode(): void
    {
        DatabaseDriverRegistry::register('myorm', \Quiote\Database\PdoDatabase::class);

        $handler = new DatabaseConfigHandler();
        $handler->initialize(null, []);

        $code = $handler->executeArray([
            'default'   => 'main',
            'databases' => [
                'main' => ['class' => 'myorm', 'parameters' => ['dsn' => 'sqlite::memory:']],
            ],
        ], 'test');

        $this->assertStringContainsString('new Quiote\Database\PdoDatabase();', $code);
        $this->assertStringNotContainsString('new myorm();', $code);
    }

    public function testFullyQualifiedClassPassesThroughUnchanged(): void
    {
        $handler = new DatabaseConfigHandler();
        $handler->initialize(null, []);

        $code = $handler->executeArray([
            'default'   => 'main',
            'databases' => [
                'main' => ['class' => 'Quiote\Database\PdoDatabase', 'parameters' => []],
            ],
        ], 'test');

        $this->assertStringContainsString('new Quiote\Database\PdoDatabase();', $code);
    }
}
