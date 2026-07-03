<?php

use Cycle\Database\Config\Postgres\TcpConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\ORM\EntityManager as CycleEntityManager;
use Cycle\ORM\Mapper\Mapper;
use Cycle\ORM\Select\Repository;
use Cycle\ORM\Select\Source;
use Cycle\ORM\SchemaInterface as S;
use PHPUnit\Framework\Attributes\Group;
use Quiote\Database\Adapter\Cycle\CycleDatabase;
use Quiote\Test\Database\DatabaseContainers;
use Quiote\Test\Database\Entity\CycleUser;
use Quiote\Test\Database\IntegrationTestCase;

#[Group('integration')]
class CycleIntegrationTest extends IntegrationTestCase
{
    public function testPostgresEntityCrudAndHeapClean(): void
    {
        if (!DatabaseContainers::pdoDriver('pgsql')) {
            $this->markTestSkipped('pdo_pgsql not available');
        }
        $pg = DatabaseContainers::postgres();

        $cycleConfig = [
            'default'     => 'default',
            'databases'   => ['default' => ['connection' => 'pg']],
            'connections' => [
                'pg' => new PostgresDriverConfig(
                    connection: new TcpConnectionConfig(
                        database: $pg['database'],
                        host: $pg['host'],
                        port: (int) $pg['port'],
                        user: $pg['username'],
                        password: $pg['password'],
                    ),
                ),
            ],
        ];

        $schema = [
            'user' => [
                S::ENTITY      => CycleUser::class,
                S::MAPPER      => Mapper::class,
                S::SOURCE      => Source::class,
                S::REPOSITORY  => Repository::class,
                S::DATABASE    => 'default',
                S::TABLE       => 'cycle_users',
                S::PRIMARY_KEY => 'id',
                S::COLUMNS     => ['id' => 'id', 'name' => 'name'],
                S::TYPECAST    => ['id' => 'int'],
                S::SCHEMA      => [],
                S::RELATIONS   => [],
            ],
        ];

        $db = new CycleDatabase();
        $this->makeManager(['cycle' => [$db, ['cycle' => $cycleConfig, 'schema' => $schema]]]);

        $database = $db->getCycleDatabaseManager()->database('default');
        $database->execute('DROP TABLE IF EXISTS cycle_users');
        $database->execute('CREATE TABLE cycle_users (id SERIAL PRIMARY KEY, name VARCHAR(255) NOT NULL)');

        $orm = $db->getOrm();

        $user = new CycleUser();
        $user->name = 'grace';
        $manager = new CycleEntityManager($orm);
        $manager->persist($user);
        $manager->run();
        $this->assertNotNull($user->id, 'generated id should be populated after run');

        // Heap: same instance while cached.
        $this->assertSame($user, $orm->getRepository('user')->findByPK($user->id));

        // Heap clean — what the adapter's reset() does per request — so a
        // re-find rehydrates a fresh instance from the DB.
        $orm->getHeap()->clean();
        $reloaded = $orm->getRepository('user')->findByPK($user->id);
        $this->assertNotSame($user, $reloaded);
        $this->assertSame('grace', $reloaded->name);

        $this->assertTrue($db->ping());
    }
}
