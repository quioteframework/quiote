<?php

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Testing\UnitTestCase;
use Quiote\Translation\GettextTranslator;
use Quiote\Translation\QuioteLocale;
use Quiote\Util\Toolkit;

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

    /**
     * Write a minimal valid little-endian .mo file with the given msgid => msgstr map.
     * @param array<string, string> $pairs
     */
    private function writeMo(string $path, array $pairs): void
    {
        $ids = array_keys($pairs);
        sort($ids, SORT_STRING);
        $n = count($ids);

        $headerSize = 28;
        $offset = $headerSize + $n * 8 * 2;

        $origData = '';
        $origTable = '';
        foreach ($ids as $id) {
            $len = strlen($id);
            $origTable .= pack('VV', $len, $offset);
            $origData .= $id . "\0";
            $offset += $len + 1;
        }
        $transData = '';
        $transTable = '';
        foreach ($ids as $id) {
            $str = $pairs[$id];
            $len = strlen($str);
            $transTable .= pack('VV', $len, $offset);
            $transData .= $str . "\0";
            $offset += $len + 1;
        }

        $mo = pack('V', 0x950412de)
            . pack('V', 0)
            . pack('V', $n)
            . pack('V', $headerSize)
            . pack('V', $headerSize + $n * 8)
            . pack('V', 0)
            . pack('V', 0)
            . $origTable . $transTable . $origData . $transData;

        file_put_contents($path, $mo);
    }

    public function testTranslatePluralFormsHeaderSelectsCorrectPluralIndex(): void
    {
        $domainDir = sys_get_temp_dir() . '/gt-plural-' . bin2hex(random_bytes(8));
        Toolkit::mkdir($domainDir, 0777, true);
        $this->dirsToDelete[] = $domainDir;

        $this->writeMo($domainDir . '/en_US.mo', [
            '' => "Plural-Forms: nplurals=2; plural=n != 1;\n",
            "one item\0many items" => "singular form\0plural form",
        ]);

        $translator = new GettextTranslator();
        $translator->initialize($this->getContext(), ['text_domains' => ['greeting' => $domainDir]]);

        $singular = $translator->translate(['one item', 'many items', 1], 'greeting', $this->makeLocale('en_US'));
        $plural = $translator->translate(['one item', 'many items', 5], 'greeting', $this->makeLocale('en_US'));

        // n=1 -> "n != 1" is false (0) -> singular form; n=5 -> true (1) -> plural form.
        $this->assertSame('singular form', $singular);
        $this->assertSame('plural form', $plural);
    }

    public function testDomainDataCacheIsReusedAcrossInstancesForSameLocaleDomainAndPath(): void
    {
        $domainDir = sys_get_temp_dir() . '/gt-cache-shared-' . bin2hex(random_bytes(8));
        Toolkit::mkdir($domainDir, 0777, true);
        $this->dirsToDelete[] = $domainDir;
        $this->writeMo($domainDir . '/en_US.mo', ['' => '', 'Hello' => 'Bonjour']);

        $first = new GettextTranslator();
        $first->initialize($this->getContext(), ['text_domains' => ['greeting' => $domainDir]]);
        $this->assertSame('Bonjour', $first->translate('Hello', 'greeting', $this->makeLocale('en_US')));

        // Overwrite the .mo file with different content: a second instance
        // sharing (locale, domain, resolved path) must still see the FIRST
        // instance's cached result rather than re-reading disk, proving the
        // per-worker cache -- not just per-instance state -- was hit.
        $this->writeMo($domainDir . '/en_US.mo', ['' => '', 'Hello' => 'CHANGED']);

        $second = new GettextTranslator();
        $second->initialize($this->getContext(), ['text_domains' => ['greeting' => $domainDir]]);
        $this->assertSame('Bonjour', $second->translate('Hello', 'greeting', $this->makeLocale('en_US')));
    }

    public function testDomainDataCacheIsIsolatedByResolvedBasePath(): void
    {
        $dirA = sys_get_temp_dir() . '/gt-cache-a-' . bin2hex(random_bytes(8));
        $dirB = sys_get_temp_dir() . '/gt-cache-b-' . bin2hex(random_bytes(8));
        Toolkit::mkdir($dirA, 0777, true);
        Toolkit::mkdir($dirB, 0777, true);
        $this->dirsToDelete[] = $dirA;
        $this->dirsToDelete[] = $dirB;
        $this->writeMo($dirA . '/en_US.mo', ['' => '', 'Hello' => 'FromA']);
        $this->writeMo($dirB . '/en_US.mo', ['' => '', 'Hello' => 'FromB']);

        // Same domain name ('greeting'), same locale, but two instances
        // configure it to different filesystem paths -- must not collide in
        // the shared static cache (the bug this test guards against: keying
        // the cache by locale+domain alone conflated distinct configurations).
        $translatorA = new GettextTranslator();
        $translatorA->initialize($this->getContext(), ['text_domains' => ['greeting' => $dirA]]);
        $translatorB = new GettextTranslator();
        $translatorB->initialize($this->getContext(), ['text_domains' => ['greeting' => $dirB]]);

        $this->assertSame('FromA', $translatorA->translate('Hello', 'greeting', $this->makeLocale('en_US')));
        $this->assertSame('FromB', $translatorB->translate('Hello', 'greeting', $this->makeLocale('en_US')));
        // Re-check A again after B loaded, to prove B's load didn't clobber A's cache entry.
        $this->assertSame('FromA', $translatorA->translate('Hello', 'greeting', $this->makeLocale('en_US')));
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
