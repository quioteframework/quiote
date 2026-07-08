<?php

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Testing\UnitTestCase;
use Quiote\Translation\GettextTranslator;
use Quiote\Translation\QuioteLocale;

/**
 * Happy + failure path coverage for GettextTranslator, which previously had
 * almost no dedicated test coverage (5% lines).
 */
class GettextTranslatorTest extends UnitTestCase
{
    /** @var list<string> */
    private array $dirsToDelete = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->dirsToDelete as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    private function makeLocale(string $identifier): QuioteLocale
    {
        $loc = new QuioteLocale();
        $ctx = $this->createStub(Context::class);
        $loc->initialize($ctx, [], $identifier, []);
        return $loc;
    }

    public function testTranslateFallsBackToOriginalMessageWhenNoMoFileExists(): void
    {
        $translator = new GettextTranslator();
        $translator->initialize($this->getContext(), ['text_domains' => ['greeting' => sys_get_temp_dir()]]);

        $result = $translator->translate('Hello', 'greeting', $this->makeLocale('en_US'));

        $this->assertSame('Hello', $result);
    }

    public function testTranslatePluralFallsBackToSingularOrPluralByCount(): void
    {
        $translator = new GettextTranslator();
        $translator->initialize($this->getContext(), ['text_domains' => ['greeting' => sys_get_temp_dir()]]);

        $singular = $translator->translate(['one item', 'many items', 1], 'greeting', $this->makeLocale('en_US'));
        $plural = $translator->translate(['one item', 'many items', 5], 'greeting', $this->makeLocale('en_US'));

        $this->assertSame('one item', $singular);
        $this->assertSame('many items', $plural);
    }

    public function testLoadDomainDataThrowsWhenDomainHasNoConfiguredPath(): void
    {
        $translator = new GettextTranslator();
        $translator->initialize($this->getContext(), []);

        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('Using domain "unconfigured" which has no path specified');
        $translator->translate('Hello', 'unconfigured', $this->makeLocale('en_US'));
    }

    public function testLoadDomainDataThrowsWhenTranslatorHasNoLocale(): void
    {
        $translator = new GettextTranslator();
        $translator->initialize($this->getContext(), ['text_domains' => ['greeting' => sys_get_temp_dir()]]);

        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('Cannot load domain data: GettextTranslator has not been prepared with a locale yet.');
        // No locale ever supplied (neither via localeChanged() nor as an argument here).
        $translator->translate('Hi', 'greeting');
    }

    public function testLocaleChangedResetsDomainDataAndPluralForm(): void
    {
        $translator = new GettextTranslator();
        $translator->initialize($this->getContext(), ['text_domains' => ['greeting' => sys_get_temp_dir()]]);

        // Prime domainData for 'greeting' via a translate() call (falls back, since no .mo file exists).
        $translator->translate('Hi', 'greeting', $this->makeLocale('en_US'));

        // Changing locale must clear cached domain data so the next translate() reloads it.
        $translator->localeChanged($this->makeLocale('de_DE'));

        // No exception, no stale data leaking across the locale change.
        $result = $translator->translate('Hi', 'greeting', $this->makeLocale('de_DE'));
        $this->assertSame('Hi', $result);
    }

    public function testResetClearsAllInternalState(): void
    {
        $translator = new GettextTranslator();
        $translator->initialize($this->getContext(), ['text_domains' => ['greeting' => sys_get_temp_dir()]]);
        $translator->translate('Hi', 'greeting', $this->makeLocale('en_US'));

        $translator->reset();

        $this->assertNull($translator->getContext());
        // After reset, the domain path config is gone too, so this now throws
        // exactly like a never-configured domain would.
        $this->expectException(QuioteException::class);
        $translator->translate('Hi', 'greeting', $this->makeLocale('en_US'));
    }

    public function testStoreCallsWritesGettextCallLogForDevelMode(): void
    {
        $storeDir = sys_get_temp_dir() . '/gt-store-' . bin2hex(random_bytes(8));
        $this->dirsToDelete[] = $storeDir;

        $translator = new GettextTranslator();
        $translator->initialize($this->getContext(), [
            'text_domains' => ['greeting' => sys_get_temp_dir()],
            'store_calls' => $storeDir,
        ]);

        $translator->translate('Hello', 'greeting', $this->makeLocale('en_US'));
        $translator->translate(['one item', 'many items', 3], 'greeting', $this->makeLocale('en_US'));

        $logFile = $storeDir . '/greeting.php';
        $this->assertFileExists($logFile);
        $contents = file_get_contents($logFile);
        if ($contents === false) {
            throw new \RuntimeException('Expected to read the call log file.');
        }
        $this->assertStringContainsString("gettext('Hello')", $contents);
        $this->assertStringContainsString("ngettext('one item', 'many items', 3)", $contents);
    }
}
