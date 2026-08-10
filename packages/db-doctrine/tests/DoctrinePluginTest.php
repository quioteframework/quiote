<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Doctrine\DoctrineDatabase;
use Quiote\Database\Adapter\Doctrine\DoctrineDbalDatabase;
use Quiote\Database\Adapter\Doctrine\DoctrinePlugin;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Plugin\PluginRegistrar;

/**
 * The plugin is what lets an application write `class="doctrine"` or
 * `class="doctrine_dbal"` in databases.xml instead of the adapter's FQCN.
 * Both aliases come from the one plugin, so both have to land.
 */
final class DoctrinePluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetRegistry(): void
    {
        DatabaseDriverRegistry::reset();
    }

    public function testRegisterAddsBothDoctrineDriverAliases(): void
    {
        (new DoctrinePlugin())->register(new PluginRegistrar('quiote/doctrine'));

        $this->assertSame(DoctrineDatabase::class, DatabaseDriverRegistry::resolve('doctrine'));
        $this->assertSame(DoctrineDbalDatabase::class, DatabaseDriverRegistry::resolve('doctrine_dbal'));
    }
}
