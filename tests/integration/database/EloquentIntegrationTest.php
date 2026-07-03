<?php

use PHPUnit\Framework\Attributes\Group;
use Quiote\Database\Adapter\Eloquent\EloquentDatabase;
use Quiote\Database\PdoDatabase;
use Quiote\Test\Database\DatabaseContainers;
use Quiote\Test\Database\IntegrationTestCase;

#[Group('integration')]
class EloquentIntegrationTest extends IntegrationTestCase
{
    public function testPostgresQueryBuilderCrud(): void
    {
        if (!DatabaseContainers::pdoDriver('pgsql')) {
            $this->markTestSkipped('pdo_pgsql not available');
        }
        $pg = DatabaseContainers::postgres();

        $db = new EloquentDatabase();
        $this->makeManager(['eloquent' => [$db, [
            'driver'   => 'pgsql',
            'host'     => $pg['host'],
            'port'     => $pg['port'],
            'database' => $pg['database'],
            'username' => $pg['username'],
            'password' => $pg['password'],
        ]]]);

        $conn = $db->getEloquentConnection();
        $conn->statement('DROP TABLE IF EXISTS eloquent_items');
        $conn->statement('CREATE TABLE eloquent_items (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL)');

        $conn->table('eloquent_items')->insert(['name' => 'gadget']);

        $this->assertSame('gadget', $conn->table('eloquent_items')->where('name', 'gadget')->value('name'));
        $this->assertSame(1, $conn->table('eloquent_items')->count());
        $this->assertTrue($db->ping());
    }

    public function testLayerModeBorrowsPdoFromReferencedDatabase(): void
    {
        if (!DatabaseContainers::pdoDriver('pgsql')) {
            $this->markTestSkipped('pdo_pgsql not available');
        }
        $pg = DatabaseContainers::postgres();

        $pdoDb = new PdoDatabase();
        $eloquent = new EloquentDatabase();
        $this->makeManager([
            'pdo_main' => [$pdoDb, [
                'dsn'      => sprintf('pgsql:host=%s;port=%d;dbname=%s', $pg['host'], $pg['port'], $pg['database']),
                'username' => $pg['username'],
                'password' => $pg['password'],
            ]],
            'eloquent' => [$eloquent, [
                'connection' => 'pdo_main',
                'driver'     => 'pgsql',
            ]],
        ]);

        // Eloquent is driving the very PDO the PdoDatabase opened.
        $this->assertSame($pdoDb->getConnection(), $eloquent->getEloquentConnection()->getPdo());
        // ...and it works end to end.
        $this->assertEquals(1, $eloquent->getEloquentConnection()->selectOne('SELECT 1 AS one')->one);
    }
}
