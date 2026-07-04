<?php

use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Group;
use Quiote\Database\Adapter\Doctrine\DoctrineDatabase;
use Quiote\Test\Database\DatabaseContainers;
use Quiote\Test\Database\Entity\DoctrineUser;
use Quiote\Test\Database\IntegrationTestCase;

#[Group('integration')]
class DoctrineOrmIntegrationTest extends IntegrationTestCase
{
    public function testPostgresEntityCrudAndIdentityMapClear(): void
    {
        if (!DatabaseContainers::pdoDriver('pgsql')) {
            $this->markTestSkipped('pdo_pgsql not available');
        }
        $pg = DatabaseContainers::postgres();

        $db = new DoctrineDatabase();
        $this->makeManager(['doctrine' => [$db, [
            'driver'       => 'pdo_pgsql',
            'host'         => $pg['host'],
            'port'         => $pg['port'],
            'dbname'       => $pg['database'],
            'user'         => $pg['username'],
            'password'     => $pg['password'],
            'entity_paths' => [dirname(__DIR__, 2) . '/lib/database/Entity'],
            'dev_mode'     => true,
        ]]]);

        $em = $db->getEntityManager();

        // Deterministic table (re)creation.
        $em->getConnection()->executeStatement('DROP TABLE IF EXISTS doctrine_users');
        $tool = new SchemaTool($em);
        $tool->createSchema([$em->getClassMetadata(DoctrineUser::class)]);

        $user = new DoctrineUser();
        $user->name = 'ada';
        $em->persist($user);
        $em->flush();
        $id = $user->id;
        $this->assertNotNull($id, 'generated id should be populated after flush');

        // Identity map: same instance while managed.
        $this->assertSame($user, $em->find(DoctrineUser::class, $id));

        // clear() — what the adapter's reset() does per request — detaches
        // everything, so a re-find hydrates a fresh instance from the DB.
        $em->clear();
        $reloaded = $em->find(DoctrineUser::class, $id);
        $this->assertNotSame($user, $reloaded);
        $this->assertSame('ada', $reloaded->name);

        $this->assertTrue($db->ping());
    }
}
