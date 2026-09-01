<?php

use PHPUnit\Framework\TestCase;
use Quiote\Plugin\PluginConfigRegistry;

/**
 * The source-of-declaration record {@see \Quiote\Config\PluginConfigHandler::apply()} populates,
 * read back by `quiote plugins:list`.
 */
final class PluginConfigRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        PluginConfigRegistry::reset();
    }

    protected function tearDown(): void
    {
        PluginConfigRegistry::reset();
    }

    public function testUnknownClassHasNoSourceRef(): void
    {
        $this->assertNull(PluginConfigRegistry::sourceRefFor('App\\Plugin\\Never\\Contributed'));
    }

    public function testContributedClassIsFoundByItsSourceRef(): void
    {
        PluginConfigRegistry::contribute(['App\\Plugin\\Always'], '/app/Config/plugins.xml');

        $this->assertSame('/app/Config/plugins.xml', PluginConfigRegistry::sourceRefFor('App\\Plugin\\Always'));
    }

    public function testTheFirstContributingFileWins(): void
    {
        PluginConfigRegistry::contribute(['App\\Plugin\\Always'], '/app/Config/plugins.xml');
        PluginConfigRegistry::contribute(['App\\Plugin\\Always'], '/app/Modules/Foo/Config/plugins.xml');

        $this->assertSame('/app/Config/plugins.xml', PluginConfigRegistry::sourceRefFor('App\\Plugin\\Always'));
    }

    public function testResetClearsEveryRecordedSourceRef(): void
    {
        PluginConfigRegistry::contribute(['App\\Plugin\\Always'], '/app/Config/plugins.xml');

        PluginConfigRegistry::reset();

        $this->assertNull(PluginConfigRegistry::sourceRefFor('App\\Plugin\\Always'));
    }
}
