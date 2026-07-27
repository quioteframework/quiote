<?php

use Quiote\Testing\UnitTestCase;
use Quiote\View\StreamTemplateLayer;

/**
 * Covers StreamTemplateLayer::getResourceStreamIdentifier(): the resolved-path
 * cache, and the stream-wrapper validation that now runs after the cache
 * lookup (only paid on a cache miss) instead of before it.
 */
class StreamTemplateLayerTest extends UnitTestCase
{
    #[\Override]
    public function setUp(): void
    {
        // getResourceStreamIdentifier() only requires a Context when
        // 'core.use_translation' is on (to look up the current locale); pin
        // it off so these tests don't depend on whatever another test in the
        // same process left this global config flag set to.
        \Quiote\Config\Config::set('core.use_translation', false);
    }

    public function testUnknownStreamWrapperThrows(): void
    {
        $layer = new StreamTemplateLayer([
            'scheme' => 'definitely-not-a-real-wrapper',
            'template' => 'whatever',
        ]);

        $this->expectException(\Quiote\Exception\QuioteException::class);
        $this->expectExceptionMessageMatches('/Unknown stream wrapper/');
        $layer->getResourceStreamIdentifier();
    }

    public function testResolvesAndCachesAFileSchemeTemplate(): void
    {
        $dir = sys_get_temp_dir() . '/quiote-stl-' . bin2hex(random_bytes(8));
        mkdir($dir);
        $path = $dir . '/tpl.txt';
        file_put_contents($path, 'x');

        try {
            $layer = new StreamTemplateLayer([
                'scheme' => 'file',
                'check' => true,
                'targets' => [$path],
                'template' => 'unused',
            ]);

            $resolved = $layer->getResourceStreamIdentifier();
            $this->assertSame($path, $resolved);

            // A second, freshly constructed layer with identical resolution
            // inputs must hit the static resolvedCache and return the same path.
            $layerAgain = new StreamTemplateLayer([
                'scheme' => 'file',
                'check' => true,
                'targets' => [$path],
                'template' => 'unused',
            ]);
            $this->assertSame($path, $layerAgain->getResourceStreamIdentifier());
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testNullTemplateReturnsNullWithoutValidatingScheme(): void
    {
        // No 'template' parameter at all -- must short-circuit to null before
        // ever reaching the (now-invalid) scheme check.
        $layer = new StreamTemplateLayer(['scheme' => 'definitely-not-a-real-wrapper']);

        $this->assertNull($layer->getResourceStreamIdentifier());
    }
}
