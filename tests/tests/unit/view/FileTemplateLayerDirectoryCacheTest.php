<?php

use Quiote\Testing\UnitTestCase;
use Quiote\View\FileTemplateLayer;

/**
 * Covers FileTemplateLayer's directory-expansion cache: getResourceStreamIdentifier()
 * used to re-run array_filter/array_combine/expandVariables on the directory
 * pattern on every single call, entirely before delegating to the parent's
 * own (separately cached) resolution.
 */
class FileTemplateLayerDirectoryCacheTest extends UnitTestCase
{
    private string $templateDir;

    #[\Override]
    public function setUp(): void
    {
        // getResourceStreamIdentifier() only requires a Context when
        // 'core.use_translation' is on (to look up the current locale); pin
        // it off so these tests don't depend on whatever another test in the
        // same process left this global config flag set to -- the layers
        // built below are never initialize()'d with a Context.
        \Quiote\Config\Config::set('core.use_translation', false);

        $this->templateDir = sys_get_temp_dir() . '/quiote-ftl-dircache-' . bin2hex(random_bytes(8));
        mkdir($this->templateDir);
        file_put_contents($this->templateDir . '/greeting.php', '<?php echo "hi"; ?>');
    }

    #[\Override]
    public function tearDown(): void
    {
        @unlink($this->templateDir . '/greeting.php');
        @rmdir($this->templateDir);
    }

    private function makeLayer(): FileTemplateLayer
    {
        // Bypass initialize()'s evaluateModuleDirective() override (which
        // needs module config we don't want to set up here) by constructing
        // directly and setting parameters by hand -- exercises the same
        // getResourceStreamIdentifier() code path.
        $layer = new FileTemplateLayer();
        $layer->setParameter('directory', $this->templateDir);
        $layer->setParameter('template', 'greeting');
        $layer->setParameter('extension', '.php');
        return $layer;
    }

    public function testResolvesRealTemplateAndPopulatesDirectoryCache(): void
    {
        $layer = $this->makeLayer();

        $resolved = $layer->getResourceStreamIdentifier();

        $this->assertSame($this->templateDir . '/greeting.php', $resolved);

        $cacheProp = new ReflectionProperty(FileTemplateLayer::class, 'directoryCache');
        /** @var array<string, string> $cache */
        $cache = $cacheProp->getValue();
        $this->assertContains($this->templateDir, $cache, 'the expanded directory must be cached');
    }

    public function testSecondCallWithSameParametersReusesCachedExpansionRatherThanRecomputing(): void
    {
        $layer = $this->makeLayer();
        $first = $layer->getResourceStreamIdentifier();

        // Corrupt the cached directory-expansion entry with a sentinel that
        // does NOT contain a real template file. A second call with the
        // exact same (directory, params) must still use this sentinel
        // instead of recomputing the real expansion -- proving the cache
        // path, not the fresh-expansion path, was taken.
        $cacheProp = new ReflectionProperty(FileTemplateLayer::class, 'directoryCache');
        /** @var array<string, string> $cache */
        $cache = $cacheProp->getValue();
        $sentinelDir = sys_get_temp_dir() . '/quiote-ftl-sentinel-should-not-exist';
        foreach ($cache as $key => $value) {
            if ($value === $this->templateDir) {
                $cache[$key] = $sentinelDir;
            }
        }
        $cacheProp->setValue(null, $cache);

        $layerAgain = $this->makeLayer();
        try {
            $layerAgain->getResourceStreamIdentifier();
            $this->fail('Expected resolution to fail against the sentinel (uncached) directory.');
        } catch (\Quiote\Exception\QuioteException $e) {
            $this->assertStringContainsString($sentinelDir, $e->getMessage());
        }

        // Restore so other tests in this run aren't affected.
        $cacheProp->setValue(null, []);
        $this->assertSame($this->templateDir . '/greeting.php', $first);
    }
}
