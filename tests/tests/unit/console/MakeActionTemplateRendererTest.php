<?php

use PHPUnit\Framework\TestCase;
use Quiote\Console\Application;
use Quiote\Renderer\PhpRenderer;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `make:action`'s template is the one generated file whose syntax the
 * generator cannot know: it belongs to whichever renderer the app configures
 * for `html`. This used to be hardcoded PHP, so a PHPTAL/Twig/XSLT app got a
 * `.php` file full of PHP tags its renderer would never execute. The content
 * and extension now come from Renderer::getStarterTemplate() /
 * getDefaultExtension().
 *
 * Uses renderers defined inside the throwaway app rather than the real
 * twig/phptal/xslt packages, so the behaviour is covered without this test
 * depending on any optional package being installed.
 */
final class MakeActionTemplateRendererTest extends TestCase
{
    use QuioteCliProcessTrait;

    private static string $appDir;
    private static string $pristineOutputTypes;

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . '/quiote-make-action-renderer-test-' . uniqid();

        $application = new Application();
        $tester = new CommandTester($application->find('new'));
        $exitCode = $tester->execute([
            'path' => self::$appDir,
            '--namespace' => 'DemoApp',
        ]);
        if ($exitCode !== 0) {
            throw new \RuntimeException('quiote new failed: ' . $tester->getDisplay());
        }

        $pristine = file_get_contents(self::$appDir . '/Config/output_types.xml');
        self::$pristineOutputTypes = is_string($pristine) ? $pristine : '';
    }

    public static function tearDownAfterClass(): void
    {
        self::removeDirectory(self::$appDir);
    }

    protected function tearDown(): void
    {
        // Each test repoints the html output type; restore so they don't leak.
        file_put_contents(self::$appDir . '/Config/output_types.xml', self::$pristineOutputTypes);
        self::clearConfigCache();
    }

    /**
     * Quiote compiles Config/*.xml into cache/config and checks freshness by
     * mtime, which has one-second granularity -- these tests rewrite
     * output_types.xml far faster than that, so a stale compiled copy would
     * otherwise win and the repointed renderer would never be seen.
     */
    private static function clearConfigCache(): void
    {
        foreach (glob(self::$appDir . '/cache/config/*.php') ?: [] as $cached) {
            unlink($cached);
        }
    }

    public function testUsesTheConfiguredPhpRendererStarterByDefault(): void
    {
        [$exitCode, $stdout] = $this->runCli([
            'make:action', 'Post', '--app-dir', self::$appDir, '--methods', 'GET',
        ]);
        $this->assertSame(0, $exitCode, $stdout);

        $template = self::$appDir . '/Modules/Default/Templates/PostSuccess.php';
        $this->assertFileExists($template);
        $this->assertSame((new PhpRenderer())->getStarterTemplate(), file_get_contents($template));
    }

    public function testUsesTheConfiguredRenderersOwnSyntaxAndExtension(): void
    {
        $this->installRenderer('Fancy', '.fancy', "return '<p>{{ title }}</p>' . \"\\n\";");
        $this->pointHtmlAt('fancy', 'DemoApp\\Renderer\\FancyRenderer');

        [$exitCode, $stdout] = $this->runCli([
            'make:action', 'Widget', '--app-dir', self::$appDir, '--methods', 'GET',
        ]);
        $this->assertSame(0, $exitCode, $stdout);

        $this->assertFileExists(self::$appDir . '/Modules/Default/Templates/WidgetSuccess.fancy');
        $this->assertSame(
            "<p>{{ title }}</p>\n",
            file_get_contents(self::$appDir . '/Modules/Default/Templates/WidgetSuccess.fancy'),
        );
        // The old hardcoded-PHP behaviour would have written this instead.
        $this->assertFileDoesNotExist(self::$appDir . '/Modules/Default/Templates/WidgetSuccess.php');
    }

    public function testWarnsInsteadOfGuessingWhenTheRendererOffersNoStarter(): void
    {
        $this->installRenderer('Bare', '.bare', 'return null;');
        $this->pointHtmlAt('bare', 'DemoApp\\Renderer\\BareRenderer');

        [$exitCode, $stdout] = $this->runCli([
            'make:action', 'Gadget', '--app-dir', self::$appDir, '--methods', 'GET',
        ]);

        // The action itself is still generated -- only the template is skipped.
        $this->assertSame(0, $exitCode, $stdout);
        $this->assertFileExists(self::$appDir . '/Modules/Default/Actions/GadgetAction.php');

        $output = self::collapseWhitespace($stdout);
        $this->assertStringContainsString('has no starter template to offer', $output);
        $this->assertStringContainsString('GadgetSuccess.bare', $output);

        // Writing a PHP file a .bare renderer can never execute is worse than writing nothing.
        $this->assertFileDoesNotExist(self::$appDir . '/Modules/Default/Templates/GadgetSuccess.php');
        $this->assertFileDoesNotExist(self::$appDir . '/Modules/Default/Templates/GadgetSuccess.bare');
    }

    /**
     * Writes a Renderer subclass into the throwaway app, where the app-namespace
     * fallback autoloader registered by AbstractAppCommand will find it.
     */
    private function installRenderer(string $name, string $extension, string $starterBody): void
    {
        $dir = self::$appDir . '/Renderer';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents("{$dir}/{$name}Renderer.php", <<<PHP
        <?php
        namespace DemoApp\\Renderer;

        use Quiote\\Renderer\\Renderer;
        use Quiote\\View\\TemplateLayer;

        class {$name}Renderer extends Renderer
        {
            protected \$defaultExtension = '{$extension}';

            public function render(TemplateLayer \$layer, array &\$attributes = [], array &\$slots = [], array &\$moreAssigns = [])
            {
                return '';
            }

            public function getStarterTemplate(): ?string
            {
                {$starterBody}
            }

            public function reset(): void {}
        }

        PHP);
    }

    private function pointHtmlAt(string $rendererName, string $rendererClass): void
    {
        $xml = self::$pristineOutputTypes;
        $xml = str_replace('<renderers default="php">', sprintf('<renderers default="%s">', $rendererName), $xml);
        $xml = str_replace(
            '<renderer name="php" class="Quiote\\Renderer\\PhpRenderer" />',
            sprintf('<renderer name="%s" class="%s" />', $rendererName, $rendererClass),
            $xml,
        );

        $this->assertStringContainsString($rendererClass, $xml, 'Failed to repoint the html output type.');
        file_put_contents(self::$appDir . '/Config/output_types.xml', $xml);
        self::clearConfigCache();
    }
}
