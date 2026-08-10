<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Eloquent\EloquentDatabase;
use Quiote\Database\Adapter\Eloquent\EloquentPlugin;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Plugin\PluginRegistrar;

/**
 * The plugin is what lets an application write `class="eloquent"` in
 * databases.xml instead of the adapter's FQCN.
 */
final class EloquentPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetRegistry(): void
    {
        DatabaseDriverRegistry::reset();
    }

    public function testRegisterAddsTheEloquentDriverAlias(): void
    {
        (new EloquentPlugin())->register(new PluginRegistrar('quiote/eloquent'));

        $this->assertSame(EloquentDatabase::class, DatabaseDriverRegistry::resolve('eloquent'));
    }
}
