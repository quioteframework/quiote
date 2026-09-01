<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Config\PluginConfigHandler;
use Quiote\Plugin\PluginConfigRegistry;

/**
 * `PluginConfigHandler::apply()` records each declared class's file in
 * {@see PluginConfigRegistry}, in addition to appending it to the flat
 * `plugins` config key -- see that handler's own docblock.
 */
final class PluginConfigHandlerSourceRefTest extends TestCase
{
    protected function tearDown(): void
    {
        PluginConfigRegistry::reset();
        Config::remove('plugins');
    }

    public function testApplyRecordsTheDeclaringFileForEachClass(): void
    {
        (new PluginConfigHandler())->apply(['App\\Plugin\\Always'], '/app/Config/plugins.xml');

        $this->assertSame('/app/Config/plugins.xml', PluginConfigRegistry::sourceRefFor('App\\Plugin\\Always'));
    }

    public function testADeferredEntryResolvedToTrueIsStillRecorded(): void
    {
        (new PluginConfigHandler())->apply(
            [['class' => 'App\\Plugin\\Replay', 'enabled' => true]],
            '/app/Modules/Foo/Config/plugins.php'
        );

        $this->assertSame(
            '/app/Modules/Foo/Config/plugins.php',
            PluginConfigRegistry::sourceRefFor('App\\Plugin\\Replay')
        );
    }

    public function testADeferredEntryResolvedToFalseIsNotRecorded(): void
    {
        (new PluginConfigHandler())->apply(
            [['class' => 'App\\Plugin\\Replay', 'enabled' => false]],
            '/app/Config/plugins.xml'
        );

        $this->assertNull(PluginConfigRegistry::sourceRefFor('App\\Plugin\\Replay'));
    }
}
