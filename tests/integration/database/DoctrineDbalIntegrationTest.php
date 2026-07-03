<?php

use PHPUnit\Framework\Attributes\Group;
use Quiote\Database\Adapter\Doctrine\DoctrineDbalDatabase;
use Quiote\Test\Database\DatabaseContainers;
use Quiote\Test\Database\IntegrationTestCase;

#[Group('integration')]
class DoctrineDbalIntegrationTest extends IntegrationTestCase
{
    public function testPostgresCrud(): void
    {
        if (!DatabaseContainers::pdoDriver('pgsql')) {
            $this->markTestSkipped('pdo_pgsql not available');
        }
        $pg = DatabaseContainers::postgres();

        $db = new DoctrineDbalDatabase();
        $this->makeManager(['dbal' => [$db, [
            'driver'   => 'pdo_pgsql',
            'host'     => $pg['host'],
            'port'     => $pg['port'],
            'dbname'   => $pg['database'],
            'user'     => $pg['username'],
            'password' => $pg['password'],
        ]]]);

        $conn = $db->getDbalConnection();
        $conn->executeStatement('DROP TABLE IF EXISTS dbal_items');
        $conn->executeStatement('CREATE TABLE dbal_items (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL)');

        // Connection helper insert + query-builder select.
        $conn->insert('dbal_items', ['name' => 'widget']);
        $name = $db->getQueryBuilder()->select('name')->from('dbal_items')->fetchOne();

        $this->assertSame('widget', $name);
        $this->assertTrue($db->ping());
    }

    public function testMysqlCrud(): void
    {
        if (!DatabaseContainers::pdoDriver('mysql')) {
            $this->markTestSkipped('pdo_mysql not available');
        }
        $my = DatabaseContainers::mysql();

        $db = new DoctrineDbalDatabase();
        $this->makeManager(['dbal' => [$db, [
            'driver'   => 'pdo_mysql',
            'host'     => $my['host'],
            'port'     => $my['port'],
            'dbname'   => $my['database'],
            'user'     => $my['username'],
            'password' => $my['password'],
        ]]]);

        $conn = $db->getDbalConnection();
        $conn->executeStatement('DROP TABLE IF EXISTS dbal_items');
        $conn->executeStatement('CREATE TABLE dbal_items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL)');

        $conn->insert('dbal_items', ['name' => 'widget']);
        $name = $db->getQueryBuilder()->select('name')->from('dbal_items')->fetchOne();

        $this->assertSame('widget', $name);
        $this->assertTrue($db->ping());
    }
}
