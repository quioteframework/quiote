<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Queue\QueueDriverInterface;
use Quiote\Queue\QueueDriverRegistry;
use Quiote\Queue\SyncQueueDriver;

final class QueueDriverRegistryNotADriver
{
}

final class QueueDriverRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        QueueDriverRegistry::reset();
    }

    public function testSyncIsRegisteredByDefault(): void
    {
        $this->assertTrue(QueueDriverRegistry::has('sync'));
        $this->assertSame(SyncQueueDriver::class, QueueDriverRegistry::resolve('sync'));
    }

    public function testRegisterAddsANewAlias(): void
    {
        QueueDriverRegistry::register('fake', QueueDriverRegistryFakeDriver::class);

        $this->assertTrue(QueueDriverRegistry::has('fake'));
        $this->assertSame(QueueDriverRegistryFakeDriver::class, QueueDriverRegistry::instantiateClassFor('fake'));
    }

    public function testResolvePassesThroughUnregisteredFqcn(): void
    {
        $this->assertSame('Some\\Fqcn', QueueDriverRegistry::resolve('Some\\Fqcn'));
    }

    public function testInstantiateClassForThrowsWhenClassDoesNotExist(): void
    {
        // Unregistered alias -> resolve() passes it through unchanged, so this
        // exercises the "does not exist" branch without violating register()'s
        // own class-string<QueueDriverInterface> contract.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        QueueDriverRegistry::instantiateClassFor('Totally\\Missing\\Class');
    }

    public function testInstantiateClassForThrowsWhenClassDoesNotImplementInterface(): void
    {
        // register()'s own signature requires class-string<QueueDriverInterface>; simulate
        // a bad alias arriving from an untyped source (e.g. a plugin) by planting it
        // directly rather than violating that contract here (mirrors
        // DatabaseDriverRegistryTest::testInstantiateNonDatabaseClassThrows()).
        $aliases = new ReflectionProperty(QueueDriverRegistry::class, 'aliases');
        $current = $aliases->getValue();
        if (!is_array($current)) {
            $this->fail('QueueDriverRegistry::$aliases is not an array');
        }
        $current['bad'] = QueueDriverRegistryNotADriver::class;
        $aliases->setValue(null, $current);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        QueueDriverRegistry::instantiateClassFor('bad');
    }

    public function testResetRestoresOnlyTheBuiltInAlias(): void
    {
        QueueDriverRegistry::register('fake', QueueDriverRegistryFakeDriver::class);
        QueueDriverRegistry::reset();

        $this->assertSame(['sync' => SyncQueueDriver::class], QueueDriverRegistry::aliases());
    }
}

final class QueueDriverRegistryFakeDriver implements QueueDriverInterface
{
    public function push(\Quiote\Queue\JobPayload $payload): void
    {
    }
}
