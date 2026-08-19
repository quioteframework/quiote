<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Replay\Store\CassetteStoreRegistry;
use Quiote\Replay\Store\FileCassetteStore;

final class CassetteStoreRegistryNotAStore
{
}

final class CassetteStoreRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        CassetteStoreRegistry::reset();
    }

    public function testFileIsRegisteredByDefault(): void
    {
        $this->assertTrue(CassetteStoreRegistry::has('file'));
        $this->assertSame(FileCassetteStore::class, CassetteStoreRegistry::resolve('file'));
    }

    public function testRegisterAddsANewAlias(): void
    {
        CassetteStoreRegistry::register('fake', CassetteStoreRegistryFakeStore::class);

        $this->assertTrue(CassetteStoreRegistry::has('fake'));
        $this->assertSame(CassetteStoreRegistryFakeStore::class, CassetteStoreRegistry::instantiateClassFor('fake'));
    }

    public function testResolvePassesThroughUnregisteredFqcn(): void
    {
        $this->assertSame('Some\\Fqcn', CassetteStoreRegistry::resolve('Some\\Fqcn'));
    }

    public function testInstantiateClassForThrowsWhenClassDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        CassetteStoreRegistry::instantiateClassFor('Totally\\Missing\\Class');
    }

    public function testInstantiateClassForThrowsWhenClassDoesNotImplementInterface(): void
    {
        $aliases = new ReflectionProperty(CassetteStoreRegistry::class, 'aliases');
        $current = $aliases->getValue();
        if (!is_array($current)) {
            $this->fail('CassetteStoreRegistry::$aliases is not an array');
        }
        $current['bad'] = CassetteStoreRegistryNotAStore::class;
        $aliases->setValue(null, $current);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        CassetteStoreRegistry::instantiateClassFor('bad');
    }

    public function testResetRestoresOnlyTheBuiltInAlias(): void
    {
        CassetteStoreRegistry::register('fake', CassetteStoreRegistryFakeStore::class);
        CassetteStoreRegistry::reset();

        $this->assertSame(['file' => FileCassetteStore::class], CassetteStoreRegistry::aliases());
    }
}

final class CassetteStoreRegistryFakeStore implements CassetteStoreInterface
{
    public function put(CassetteId $id, Cassette $cassette): void
    {
    }

    public function get(CassetteId $id): ?Cassette
    {
        return null;
    }

    public function has(CassetteId $id): bool
    {
        return false;
    }

    public function delete(CassetteId $id): void
    {
    }
}
