<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\DevOverlay\Http\HtmlScriptInjector;

final class HtmlScriptInjectorTest extends TestCase
{
    public function testInsertsTheTagBeforeTheClosingBody(): void
    {
        $html = (new HtmlScriptInjector())->inject('<html><body><p>Hi</p></body></html>', '/_quiote/overlay/overlay.js');

        $this->assertSame(
            '<html><body><p>Hi</p><script src="/_quiote/overlay/overlay.js" data-quiote-overlay defer></script></body></html>',
            $html,
        );
    }

    public function testUsesTheLastClosingBodyAndMatchesItCaseInsensitively(): void
    {
        $html = (new HtmlScriptInjector())->inject('<BODY><pre>&lt;/body&gt; </body></pre></BODY>', '/o.js');

        $this->assertIsString($html);
        $this->assertStringEndsWith('<script src="/o.js" data-quiote-overlay defer></script></BODY>', $html);
    }

    public function testRendersAndEscapesDataAttributes(): void
    {
        $html = (new HtmlScriptInjector())->inject('<body></body>', '/o.js', ['endpoint' => '/x', 'editor' => 'a"><script>']);

        $this->assertIsString($html);
        $this->assertStringContainsString('data-endpoint="/x"', $html);
        $this->assertStringContainsString('data-editor="a&quot;&gt;&lt;script&gt;"', $html);
    }

    public function testAFragmentWithoutAClosingBodyIsLeftAlone(): void
    {
        $this->assertNull((new HtmlScriptInjector())->inject('<div>partial</div>', '/o.js'));
    }

    public function testADocumentThatAlreadyCarriesTheOverlayIsLeftAlone(): void
    {
        $injector = new HtmlScriptInjector();
        $once = $injector->inject('<body></body>', '/o.js');
        $this->assertIsString($once);

        $this->assertNull($injector->inject($once, '/o.js'));
    }
}
