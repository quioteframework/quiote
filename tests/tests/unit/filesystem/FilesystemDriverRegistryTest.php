<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Filesystem\FilesystemAdapterInterface;
use Quiote\Filesystem\FilesystemDriverRegistry;
use Quiote\Filesystem\LocalFilesystemAdapter;

final class FilesystemDriverRegistryNotADriver
{
}

final class FilesystemDriverRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        FilesystemDriverRegistry::reset();
    }

    public function testLocalIsRegisteredByDefault(): void
    {
        $this->assertTrue(FilesystemDriverRegistry::has('local'));
        $this->assertSame(LocalFilesystemAdapter::class, FilesystemDriverRegistry::resolve('local'));
    }

    public function testRegisterAddsANewAlias(): void
    {
        FilesystemDriverRegistry::register('fake', FilesystemDriverRegistryFakeAdapter::class);

        $this->assertTrue(FilesystemDriverRegistry::has('fake'));
        $this->assertSame(FilesystemDriverRegistryFakeAdapter::class, FilesystemDriverRegistry::instantiateClassFor('fake'));
    }

    public function testResolvePassesThroughUnregisteredFqcn(): void
    {
        $this->assertSame('Some\\Fqcn', FilesystemDriverRegistry::resolve('Some\\Fqcn'));
    }

    public function testInstantiateClassForThrowsWhenClassDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        FilesystemDriverRegistry::instantiateClassFor('Totally\\Missing\\Class');
    }

    public function testInstantiateClassForThrowsWhenClassDoesNotImplementInterface(): void
    {
        $aliases = new ReflectionProperty(FilesystemDriverRegistry::class, 'aliases');
        $current = $aliases->getValue();
        if (!is_array($current)) {
            $this->fail('FilesystemDriverRegistry::$aliases is not an array');
        }
        $current['bad'] = FilesystemDriverRegistryNotADriver::class;
        $aliases->setValue(null, $current);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        FilesystemDriverRegistry::instantiateClassFor('bad');
    }

    public function testResetRestoresOnlyTheBuiltInAlias(): void
    {
        FilesystemDriverRegistry::register('fake', FilesystemDriverRegistryFakeAdapter::class);
        FilesystemDriverRegistry::reset();

        $this->assertSame(['local' => LocalFilesystemAdapter::class], FilesystemDriverRegistry::aliases());
    }
}

final class FilesystemDriverRegistryFakeAdapter implements FilesystemAdapterInterface
{
    public function read(string $path): string
    {
        return '';
    }

    public function write(string $path, string $contents): void
    {
    }

    public function delete(string $path): void
    {
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function size(string $path): int
    {
        return 0;
    }

    public function lastModified(string $path): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function listContents(string $path = ''): array
    {
        return [];
    }
}
