<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Database\PdoDatabase;
use Quiote\Exception\DatabaseException;

class DatabaseDriverRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        DatabaseDriverRegistry::reset();
    }

    public function testBuiltinPdoAliasResolves(): void
    {
        $this->assertTrue(DatabaseDriverRegistry::has('pdo'));
        $this->assertSame(PdoDatabase::class, DatabaseDriverRegistry::resolve('pdo'));
    }

    public function testUnknownAliasPassesThroughUnchanged(): void
    {
        // A fully-qualified class name must survive resolve() untouched.
        $this->assertSame('Quiote\\Database\\PdoDatabase', DatabaseDriverRegistry::resolve('Quiote\\Database\\PdoDatabase'));
        $this->assertSame('not_a_registered_alias', DatabaseDriverRegistry::resolve('not_a_registered_alias'));
    }

    public function testRegisterAndResolve(): void
    {
        DatabaseDriverRegistry::register('myorm', PdoDatabase::class);
        $this->assertTrue(DatabaseDriverRegistry::has('myorm'));
        $this->assertSame(PdoDatabase::class, DatabaseDriverRegistry::resolve('myorm'));
    }

    public function testInstantiateResolvesAlias(): void
    {
        $db = DatabaseDriverRegistry::instantiate('pdo');
        $this->assertInstanceOf(PdoDatabase::class, $db);
    }

    public function testInstantiateMissingClassThrows(): void
    {
        $this->expectException(DatabaseException::class);
        DatabaseDriverRegistry::instantiate('This\\Class\\Does\\Not\\Exist');
    }

    public function testInstantiateNonDatabaseClassThrows(): void
    {
        DatabaseDriverRegistry::register('bad', \stdClass::class);
        $this->expectException(DatabaseException::class);
        DatabaseDriverRegistry::instantiate('bad');
    }

    public function testResetRestoresOnlyBuiltins(): void
    {
        DatabaseDriverRegistry::register('temp', PdoDatabase::class);
        DatabaseDriverRegistry::reset();
        $this->assertFalse(DatabaseDriverRegistry::has('temp'));
        $this->assertTrue(DatabaseDriverRegistry::has('pdo'));
    }
}
