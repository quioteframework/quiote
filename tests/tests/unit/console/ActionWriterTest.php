<?php

use PHPUnit\Framework\Attributes\DataProvider;
use Quiote\Console\Command\Scaffold\ActionWriter;
use Quiote\Exception\ConfigurationException;
use Quiote\Testing\UnitTestCase;

/**
 * ActionWriter is what `make:action` actually generates with. The command
 * itself has to be driven through a subprocess CLI (see
 * QuioteCliProcessTrait), which can only assert that files appeared -- so the
 * generated *contents* and the output-type provisioning are pinned down here,
 * in-process, against a throwaway app directory.
 *
 * This runs as a UnitTestCase because ActionWriter resolves the `html`
 * renderer from the live context's output-type configuration, and the sandbox
 * app configures PhpRenderer for it.
 */
final class ActionWriterTest extends UnitTestCase
{
    private string $appDir;

    #[\Override]
    public function setUp(): void
    {
        $this->appDir = sys_get_temp_dir() . '/quiote-action-writer-' . uniqid();
        mkdir($this->appDir . '/Config', 0777, true);
    }

    #[\Override]
    public function tearDown(): void
    {
        $this->removeDirectory($this->appDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writer(string $module = 'Blog'): ActionWriter
    {
        return new ActionWriter($this->appDir, 'DemoApp', $module);
    }

    private function actionPath(string $name = 'Post', string $module = 'Blog'): string
    {
        return "{$this->appDir}/Modules/{$module}/Actions/{$name}Action.php";
    }

    private function viewPath(string $name = 'Post', string $module = 'Blog'): string
    {
        return "{$this->appDir}/Modules/{$module}/Views/{$name}SuccessView.php";
    }

    private function templatePath(string $name = 'Post', string $module = 'Blog', string $extension = '.php'): string
    {
        return "{$this->appDir}/Modules/{$module}/Templates/{$name}Success{$extension}";
    }

    /** Copies the sandbox app's output_types.xml so the XML provisioning path has something real to edit. */
    private function installOutputTypesConfig(): string
    {
        $source = dirname(__DIR__, 3) . '/sandbox/app/Config/output_types.xml';
        $this->assertFileExists($source);
        $target = $this->appDir . '/Config/output_types.xml';
        copy($source, $target);
        return $target;
    }

    // ---------------------------------------------------------------
    // The generated Action class.
    // ---------------------------------------------------------------

    public function testWritesAnActionViewAndTemplateForAnHtmlAction(): void
    {
        $warnings = $this->writer()->write('Post', ['GET'], true, ['html'], false);

        $this->assertSame([], $warnings);
        $this->assertFileExists($this->actionPath());
        $this->assertFileExists($this->viewPath());
        $this->assertFileExists($this->templatePath());
    }

    public function testGeneratedActionIsInTheAppNamespaceAndExtendsAction(): void
    {
        $this->writer()->write('Post', ['GET'], true, ['html'], false);
        $php = (string) file_get_contents($this->actionPath());

        $this->assertStringContainsString('namespace DemoApp\\Modules\\Blog\\Actions;', $php);
        $this->assertStringContainsString('class PostAction extends Action', $php);
        // Scaffolded actions declare themselves simple so dispatch skips the
        // validation config lookup they have no configuration for.
        $this->assertStringContainsString('public function isSimple(): bool', $php);
    }

    public function testGeneratedActionReturnsTheViewItAlsoGenerated(): void
    {
        $this->writer()->write('Post', ['GET'], true, ['html'], false);
        $php = (string) file_get_contents($this->actionPath());

        $this->assertStringContainsString("return 'PostSuccess';", $php);
        $this->assertMatchesRegularExpression('/getDefaultViewName\(\): string\s*\{\s*return \'PostSuccess\';/', $php);
    }

    public function testWithoutAViewTheActionFallsBackToTheGenericSuccessView(): void
    {
        $warnings = $this->writer()->write('Post', ['GET'], false, ['html'], false);
        $php = (string) file_get_contents($this->actionPath());

        $this->assertSame([], $warnings);
        $this->assertFileDoesNotExist($this->viewPath());
        $this->assertFileDoesNotExist($this->templatePath());
        $this->assertStringContainsString("return 'Success';", $php);
        $this->assertStringNotContainsString('PostSuccess', $php);
    }

    /** @return list<array{list<string>, list<string>}> */
    public static function verbMappings(): array
    {
        return [
            [['GET'], ['executeRead']],
            [['HEAD'], ['executeRead']],
            [['OPTIONS'], ['executeRead']],
            [['TRACE'], ['executeRead']],
            [['POST'], ['executeWrite']],
            [['PUT'], ['executeUpdate']],
            [['PATCH'], ['executeUpdate']],
            [['DELETE'], ['executeRemove']],
            [['GET', 'POST'], ['executeRead', 'executeWrite']],
            [['PUT', 'DELETE'], ['executeUpdate', 'executeRemove']],
        ];
    }

    /**
     * @param list<string> $verbs
     * @param list<string> $expectedMethods
     */
    #[DataProvider('verbMappings')]
    public function testHttpVerbsMapOntoTheCanonicalExecuteMethods(array $verbs, array $expectedMethods): void
    {
        $this->writer()->write('Post', $verbs, false, ['html'], false);
        $php = (string) file_get_contents($this->actionPath());

        foreach ($expectedMethods as $method) {
            $this->assertStringContainsString('public function ' . $method . '(WebRequest $rd)', $php);
        }
        $this->assertSame(count($expectedMethods), substr_count($php, 'public function execute'));
    }

    public function testVerbsSharingATokenCollapseIntoOneMethod(): void
    {
        // GET, HEAD, OPTIONS and TRACE all map to Read -- generating four
        // identical executeRead() methods would not even be valid PHP.
        $this->writer()->write('Post', ['GET', 'HEAD', 'OPTIONS', 'TRACE'], false, ['html'], false);
        $php = (string) file_get_contents($this->actionPath());

        $this->assertSame(1, substr_count($php, 'public function executeRead('));
    }

    public function testGeneratedActionIsSyntacticallyValidPhp(): void
    {
        $this->writer()->write('Post', ['GET', 'POST'], true, ['html', 'json'], false);

        $this->assertPhpParses($this->actionPath());
        $this->assertPhpParses($this->viewPath());
    }

    private function assertPhpParses(string $path): void
    {
        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, $path . ' does not parse: ' . implode("\n", $output));
    }

    // ---------------------------------------------------------------
    // The generated View class.
    // ---------------------------------------------------------------

    public function testGeneratedViewHasAnExecuteMethodPerOutputType(): void
    {
        $this->writer()->write('Post', ['GET'], true, ['html', 'json'], false);
        $php = (string) file_get_contents($this->viewPath());

        $this->assertStringContainsString('class PostSuccessView extends View', $php);
        $this->assertStringContainsString('public function executeHtml(WebRequest $rd): void', $php);
        $this->assertStringContainsString('public function executeJson(WebRequest $rd): string', $php);
        // The html variant loads a layout; the others serialize directly.
        $this->assertStringContainsString('$this->loadLayout();', $php);
        $this->assertStringContainsString('json_encode(', $php);
    }

    public function testGeneratedViewFallsBackToAnExplanatoryException(): void
    {
        $this->writer()->write('Post', ['GET'], true, ['html'], false);
        $php = (string) file_get_contents($this->viewPath());

        // execute() is the "output type you did not scaffold" path.
        $this->assertStringContainsString('public function execute(WebRequest $rd): never', $php);
        $this->assertStringContainsString('ViewException', $php);
    }

    public function testANonHtmlOnlyViewGetsNoTemplate(): void
    {
        $this->installOutputTypesConfig();

        $this->writer()->write('Post', ['GET'], true, ['json'], false);

        $this->assertFileExists($this->viewPath());
        $this->assertFileDoesNotExist($this->templatePath());
    }

    // ---------------------------------------------------------------
    // The generated template comes from the configured renderer.
    // ---------------------------------------------------------------

    public function testTemplateComesFromTheConfiguredHtmlRenderer(): void
    {
        $this->writer()->write('Post', ['GET'], true, ['html'], false);

        // The sandbox app renders html with PhpRenderer, whose starter template
        // is a .php file -- the extension is the renderer's, not a hardcoded one.
        $renderer = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class)->getOutputType('html')->getRenderer();
        $this->assertNotNull($renderer, 'the sandbox app configures a renderer for html');
        $extension = $renderer->getDefaultExtension() ?: '.php';
        $this->assertFileExists($this->templatePath('Post', 'Blog', $extension));
        $this->assertStringEqualsFile(
            $this->templatePath('Post', 'Blog', $extension),
            (string) $renderer->getStarterTemplate()
        );
    }

    // ---------------------------------------------------------------
    // Overwrite guards.
    // ---------------------------------------------------------------

    public function testRefusesToOverwriteAnExistingActionWithoutForce(): void
    {
        $this->writer()->write('Post', ['GET'], false, ['html'], false);
        file_put_contents($this->actionPath(), '<?php // hand-edited');

        try {
            $this->writer()->write('Post', ['GET'], false, ['html'], false);
            $this->fail('Expected a ConfigurationException for the existing action file.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertStringEqualsFile($this->actionPath(), '<?php // hand-edited');
    }

    public function testRefusesToOverwriteAnExistingViewWithoutForce(): void
    {
        $this->writer()->write('Post', ['GET'], true, ['html'], false);
        // The action is regenerated with --force, but the view must still be guarded.
        unlink($this->actionPath());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('already exists');
        $this->writer()->write('Post', ['GET'], true, ['html'], false);
    }

    public function testForceOverwritesEverythingItGenerated(): void
    {
        $this->writer()->write('Post', ['GET'], true, ['html'], false);
        file_put_contents($this->actionPath(), '<?php // stale');

        $this->writer()->write('Post', ['POST'], true, ['html'], true);

        $php = (string) file_get_contents($this->actionPath());
        $this->assertStringNotContainsString('stale', $php);
        $this->assertStringContainsString('public function executeWrite(', $php);
    }

    // ---------------------------------------------------------------
    // output_types.xml provisioning.
    // ---------------------------------------------------------------

    public function testProvisionsAKnownOutputTypeInTheAppsConfig(): void
    {
        $configPath = $this->installOutputTypesConfig();

        $warnings = $this->writer()->write('Post', ['GET'], true, ['html', 'json'], false);

        $this->assertSame([], $warnings);
        $xml = (string) file_get_contents($configPath);
        $this->assertStringContainsString('name="json"', $xml);
        $this->assertStringContainsString('application/json; charset=UTF-8', $xml);
        $this->assertStringContainsString('Quiote\\Renderer\\PhpRenderer', $xml);
    }

    public function testProvisionedConfigIsStillValidXmlWithTheOriginalEntriesIntact(): void
    {
        $configPath = $this->installOutputTypesConfig();

        $this->writer()->write('Post', ['GET'], true, ['html', 'json', 'xml', 'text'], false);

        $dom = new \DOMDocument();
        $this->assertTrue($dom->load($configPath));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ot', 'http://quiote.dev/quiote/config/parts/output_types/1.1');
        foreach (['html', 'json', 'xml', 'text'] as $type) {
            $nodes = $xpath->query('//ot:output_type[@name="' . $type . '"]');
            $this->assertNotFalse($nodes);
            $this->assertSame(1, $nodes->length, 'expected exactly one entry for ' . $type);
        }
    }

    public function testAnAlreadyConfiguredOutputTypeIsNotDuplicated(): void
    {
        $configPath = $this->installOutputTypesConfig();
        $this->writer()->write('Post', ['GET'], true, ['html', 'json'], false);

        $this->writer('Shop')->write('Item', ['GET'], true, ['html', 'json'], false);

        $xml = (string) file_get_contents($configPath);
        $this->assertSame(1, substr_count($xml, 'name="json"'));
    }

    public function testWarnsAboutAnOutputTypeItCannotProvision(): void
    {
        $this->installOutputTypesConfig();

        $warnings = $this->writer()->write('Post', ['GET'], true, ['html', 'csv'], false);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('csv', $warnings[0]);
        $this->assertStringContainsString('not one Quiote provisions automatically', $warnings[0]);
    }

    public function testWarnsWhenThereIsNoOutputTypesConfigToProvisionInto(): void
    {
        // No Config/output_types.xml in this throwaway app.
        $warnings = $this->writer()->write('Post', ['GET'], true, ['html', 'json'], false);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Config/output_types.xml not found', $warnings[0]);
        $this->assertStringContainsString('json', $warnings[0]);
    }

    public function testHtmlOnlyNeedsNoProvisioningAndWarnsAboutNothing(): void
    {
        $warnings = $this->writer()->write('Post', ['GET'], true, ['html'], false);

        $this->assertSame([], $warnings);
    }

    public function testWarnsWhenTheConfigHasNoOutputTypesElement(): void
    {
        file_put_contents(
            $this->appDir . '/Config/output_types.xml',
            '<?xml version="1.0" encoding="UTF-8"?><ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"/>'
        );

        $warnings = $this->writer()->write('Post', ['GET'], true, ['html', 'json'], false);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Could not locate <output_types>', $warnings[0]);
    }

    public function testRejectsAnUnparseableOutputTypesConfig(): void
    {
        file_put_contents($this->appDir . '/Config/output_types.xml', 'this is not XML at all <<<');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('as XML');
        $this->writer()->write('Post', ['GET'], true, ['html', 'json'], false);
    }
}
