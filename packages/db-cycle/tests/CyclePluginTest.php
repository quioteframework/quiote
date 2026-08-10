<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Cycle\CycleDatabase;
use Quiote\Database\Adapter\Cycle\CyclePlugin;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Plugin\PluginRegistrar;

/**
 * The plugin is what lets an application write `class="cycle"` in
 * databases.xml instead of the adapter's FQCN.
 */
final class CyclePluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetRegistry(): void
    {
        DatabaseDriverRegistry::reset();
    }

    public function testRegisterAddsTheCycleDriverAlias(): void
    {
        (new CyclePlugin())->register(new PluginRegistrar('quiote/cycle'));

        $this->assertSame(CycleDatabase::class, DatabaseDriverRegistry::resolve('cycle'));
    }
}
