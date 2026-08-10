<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Quiote\Context;
use Quiote\Request\WebRequest;
use Quiote\Response\WebResponse;
use Quiote\Testing\UnitTestCase;
use Quiote\Util\FormPopulationConfig;
use Quiote\Util\FormPopulationEngine;

/**
 * End-to-end behaviour of form population, pinned at the document level:
 * given this markup and these submitted parameters, the response body comes
 * back looking like this.
 *
 * Every assertion here is about what a browser would receive, not about which
 * object produced it, so the whole engine can be rearranged underneath without
 * these needing to change. That is the point of them -- they are the net that
 * makes decomposing the engine a safe operation rather than a hopeful one.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class FormPopulationCharacterizationTest extends UnitTestCase
{
    private ?Context $context = null;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();
        $this->context = $this->getContext();
    }

    #[\Override]
    public function tearDown(): void
    {
        $this->context = null;
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $config
     */
    private function populate(
        string $content,
        array $parameters,
        array $config = [],
        ?ServerRequest $psrRequest = null,
    ): string {
        $context = $this->context;
        if ($context === null) {
            throw new LogicException('setUp() must run first');
        }

        $engine = new FormPopulationEngine();
        $engine->initialize($context);

        $psr = $psrRequest ?? new ServerRequest('POST', 'https://example.test/');
        $request = new WebRequest(
            $psr->getMethod(),
            $psr->getUri(),
            $psr->getHeaders(),
            $psr->getBody(),
            $psr->getProtocolVersion(),
            $psr->getServerParams(),
        );
        $request->initialize($context);

        foreach ($parameters as $key => $value) {
            $request = $request->setParameter($key, $value);
        }

        $seeded = FormPopulationConfig::seed($request, $engine->getDefaults());
        if ($seeded instanceof WebRequest) {
            $request = $seeded;
        }
        if ($config !== []) {
            $merged = FormPopulationConfig::merge($request, $config);
            if ($merged instanceof WebRequest) {
                $request = $merged;
            }
        }

        $response = new WebResponse();
        $response->initialize($context);
        $response->setOutputType($context->getContainer()->get(\Quiote\Controller\Controller::class)->getOutputType());
        $response->setContent($content);

        $engine->populate($response, $request);
        $engine->reset();

        $body = $response->getContent();

        return is_string($body) ? $body : '';
    }

    private function xpath(string $content): DOMXPath
    {
        $dom = new DOMDocument();
        $dom->strictErrorChecking = false;
        $dom->recover = true;
        @$dom->loadHTML($content);

        return new DOMXPath($dom);
    }

    private function attribute(string $content, string $expression, string $attribute): ?string
    {
        $nodes = $this->xpath($content)->query($expression);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node->getAttribute($attribute) : null;
    }

    private function textOf(string $content, string $expression): ?string
    {
        $nodes = $this->xpath($content)->query($expression);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node->textContent : null;
    }

    private function nodeCount(string $content, string $expression): int
    {
        $nodes = $this->xpath($content)->query($expression);

        return $nodes === false ? 0 : $nodes->length;
    }

    private function form(string $body, string $action = '/'): string
    {
        return '<!DOCTYPE html><html><body><form action="' . $action . '">' . $body . '</form></body></html>';
    }

    // --- text-like inputs ---------------------------------------------------

    public function testATextInputTakesTheSubmittedValue(): void
    {
        $out = $this->populate($this->form('<input type="text" name="foo" value="original">'), ['foo' => 'submitted']);

        $this->assertSame('submitted', $this->attribute($out, '//input[@name="foo"]', 'value'));
    }

    /** A field with no submitted value loses the value it was rendered with. */
    public function testATextInputWithNoSubmittedValueIsCleared(): void
    {
        $out = $this->populate($this->form('<input type="text" name="foo" value="original">'), []);

        $this->assertSame('', $this->attribute($out, '//input[@name="foo"]', 'value') ?? '');
    }

    public function testAnEmptySubmittedValueIsWrittenBackAsEmpty(): void
    {
        $out = $this->populate($this->form('<input type="text" name="foo" value="original">'), ['foo' => '']);

        $this->assertSame('', $this->attribute($out, '//input[@name="foo"]', 'value'));
    }

    public function testTheValueIsHtmlEscapedOnTheWayIn(): void
    {
        $out = $this->populate($this->form('<input type="text" name="foo">'), ['foo' => '"><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $out);
        $this->assertSame('"><script>alert(1)</script>', $this->attribute($out, '//input[@name="foo"]', 'value'));
    }

    public function testAPasswordInputIsNotRefilledByDefault(): void
    {
        $out = $this->populate($this->form('<input type="password" name="secret">'), ['secret' => 'hunter2']);

        $this->assertNull($this->attribute($out, '//input[@name="secret"]', 'value') ?: null);
    }

    public function testAPasswordInputIsRefilledWhenAskedFor(): void
    {
        $out = $this->populate(
            $this->form('<input type="password" name="secret">'),
            ['secret' => 'hunter2'],
            ['include_password_inputs' => true],
        );

        $this->assertSame('hunter2', $this->attribute($out, '//input[@name="secret"]', 'value'));
    }

    public function testAHiddenInputIsPopulatedByDefault(): void
    {
        $out = $this->populate($this->form('<input type="hidden" name="token" value="old">'), ['token' => 'new']);

        $this->assertSame('new', $this->attribute($out, '//input[@name="token"]', 'value'));
    }

    public function testHiddenInputsCanBeLeftAlone(): void
    {
        $out = $this->populate(
            $this->form('<input type="hidden" name="token" value="old">'),
            ['token' => 'new'],
            ['include_hidden_inputs' => false],
        );

        $this->assertSame('old', $this->attribute($out, '//input[@name="token"]', 'value'));
    }

    /** Submit and button inputs carry their own label, so their value is left alone. */
    public function testASubmitInputKeepsItsOwnValue(): void
    {
        $out = $this->populate($this->form('<input type="submit" name="go" value="Send">'), ['go' => 'tampered']);

        $this->assertSame('Send', $this->attribute($out, '//input[@name="go"]', 'value'));
    }

    // --- checkboxes and radios ----------------------------------------------

    public function testACheckboxWithTheMatchingValueIsChecked(): void
    {
        $out = $this->populate($this->form('<input type="checkbox" name="agree" value="1">'), ['agree' => '1']);

        $this->assertSame(1, $this->nodeCount($out, '//input[@name="agree"][@checked]'));
    }

    public function testACheckboxWhoseValueWasNotSubmittedIsUnchecked(): void
    {
        $out = $this->populate($this->form('<input type="checkbox" name="agree" value="1" checked="checked">'), []);

        $this->assertSame(0, $this->nodeCount($out, '//input[@name="agree"][@checked]'));
    }

    /** A pre-checked box whose value was not the submitted one must lose the check. */
    public function testACheckboxWithADifferentValueIsUnchecked(): void
    {
        $out = $this->populate(
            $this->form('<input type="checkbox" name="colour" value="red" checked="checked">'),
            ['colour' => 'blue'],
        );

        $this->assertSame(0, $this->nodeCount($out, '//input[@name="colour"][@checked]'));
    }

    public function testOnlyTheSubmittedRadioIsChecked(): void
    {
        $markup = '<input type="radio" name="size" value="s"><input type="radio" name="size" value="m">';
        $out = $this->populate($this->form($markup), ['size' => 'm']);

        $this->assertSame(1, $this->nodeCount($out, '//input[@name="size"][@checked]'));
        $this->assertSame(1, $this->nodeCount($out, '//input[@name="size"][@value="m"][@checked]'));
    }

    /** An array-named checkbox group checks every submitted member. */
    public function testAnArrayCheckboxGroupChecksEverySubmittedValue(): void
    {
        $markup = '<input type="checkbox" name="tags[]" value="a">'
            . '<input type="checkbox" name="tags[]" value="b">'
            . '<input type="checkbox" name="tags[]" value="c">';

        $out = $this->populate($this->form($markup), ['tags' => ['a', 'c']]);

        $this->assertSame(2, $this->nodeCount($out, '//input[@checked]'));
        $this->assertSame(1, $this->nodeCount($out, '//input[@value="a"][@checked]'));
        $this->assertSame(1, $this->nodeCount($out, '//input[@value="c"][@checked]'));
        $this->assertSame(0, $this->nodeCount($out, '//input[@value="b"][@checked]'));
    }

    // --- selects -------------------------------------------------------------

    public function testTheSubmittedOptionIsSelected(): void
    {
        $markup = '<select name="colour"><option value="red">Red</option><option value="blue">Blue</option></select>';
        $out = $this->populate($this->form($markup), ['colour' => 'blue']);

        $this->assertSame(1, $this->nodeCount($out, '//option[@value="blue"][@selected]'));
        $this->assertSame(0, $this->nodeCount($out, '//option[@value="red"][@selected]'));
    }

    public function testAPreviouslySelectedOptionIsDeselected(): void
    {
        $markup = '<select name="colour"><option value="red" selected="selected">Red</option>'
            . '<option value="blue">Blue</option></select>';
        $out = $this->populate($this->form($markup), ['colour' => 'blue']);

        $this->assertSame(0, $this->nodeCount($out, '//option[@value="red"][@selected]'));
    }

    public function testAMultipleSelectSelectsEverySubmittedOption(): void
    {
        $markup = '<select name="colours[]" multiple="multiple">'
            . '<option value="red">Red</option><option value="green">Green</option>'
            . '<option value="blue">Blue</option></select>';

        $out = $this->populate($this->form($markup), ['colours' => ['red', 'blue']]);

        $this->assertSame(2, $this->nodeCount($out, '//option[@selected]'));
        $this->assertSame(0, $this->nodeCount($out, '//option[@value="green"][@selected]'));
    }

    /** Options inside an optgroup are reached too, which is why selection uses XPath. */
    public function testAnOptionInsideAnOptgroupIsSelected(): void
    {
        $markup = '<select name="colour"><optgroup label="Warm"><option value="red">Red</option></optgroup>'
            . '<optgroup label="Cool"><option value="blue">Blue</option></optgroup></select>';

        $out = $this->populate($this->form($markup), ['colour' => 'blue']);

        $this->assertSame(1, $this->nodeCount($out, '//option[@value="blue"][@selected]'));
    }

    // --- textareas ------------------------------------------------------------

    public function testATextareaTakesTheSubmittedValueAsItsText(): void
    {
        $out = $this->populate($this->form('<textarea name="body">original</textarea>'), ['body' => 'submitted']);

        $this->assertSame('submitted', $this->textOf($out, '//textarea[@name="body"]'));
    }

    public function testATextareaWithNoSubmittedValueIsEmptied(): void
    {
        $out = $this->populate($this->form('<textarea name="body">original</textarea>'), []);

        $this->assertSame('', $this->textOf($out, '//textarea[@name="body"]'));
    }

    // --- field naming ----------------------------------------------------------

    public function testAnIndexedFieldNameReadsTheNestedParameter(): void
    {
        $out = $this->populate(
            $this->form('<input type="text" name="user[name]">'),
            ['user' => ['name' => 'Markus']],
        );

        $this->assertSame('Markus', $this->attribute($out, '//input[@name="user[name]"]', 'value'));
    }

    /** Successive empty brackets take successive indices of the submitted list. */
    public function testSuccessiveEmptyBracketsTakeSuccessiveIndices(): void
    {
        $markup = '<input type="text" name="tags[]"><input type="text" name="tags[]">';
        $out = $this->populate($this->form($markup), ['tags' => ['first', 'second']]);

        $values = [];
        $nodes = $this->xpath($out)->query('//input');
        $this->assertNotFalse($nodes);
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $values[] = $node->getAttribute('value');
            }
        }

        $this->assertSame(['first', 'second'], $values);
    }

    // --- which forms and fields are touched -------------------------------------

    public function testAFieldNamedInSkipIsLeftAlone(): void
    {
        $markup = '<input type="text" name="keep" value="original"><input type="text" name="touch" value="original">';
        $out = $this->populate($this->form($markup), ['keep' => 'new', 'touch' => 'new'], ['skip' => ['keep']]);

        $this->assertSame('original', $this->attribute($out, '//input[@name="keep"]', 'value'));
        $this->assertSame('new', $this->attribute($out, '//input[@name="touch"]', 'value'));
    }

    /** A field outside any form is not the engine's business. */
    public function testAnInputOutsideAFormIsNotTouched(): void
    {
        $html = '<!DOCTYPE html><html><body><input type="text" name="loose" value="original">'
            . '<form action="/"><input type="text" name="inside" value="original"></form></body></html>';

        $out = $this->populate($html, ['loose' => 'new', 'inside' => 'new']);

        $this->assertSame('original', $this->attribute($out, '//input[@name="loose"]', 'value'));
        $this->assertSame('new', $this->attribute($out, '//input[@name="inside"]', 'value'));
    }

    /** A form posting somewhere else is not this request's form. */
    public function testAFormWhoseActionDoesNotMatchTheRequestIsSkipped(): void
    {
        $out = $this->populate(
            $this->form('<input type="text" name="foo" value="original">', 'https://elsewhere.test/other'),
            ['foo' => 'new'],
        );

        $this->assertSame('original', $this->attribute($out, '//input[@name="foo"]', 'value'));
    }

    // --- document handling --------------------------------------------------------

    public function testThePopulatedDocumentStaysWellFormed(): void
    {
        $out = $this->populate($this->form('<input type="text" name="foo">'), ['foo' => 'bar']);

        $this->assertStringContainsString('<form', $out);
        $this->assertStringContainsString('</form>', $out);
        $this->assertStringContainsString('</html>', $out);
    }

    /** Markup the engine has no business in comes back untouched. */
    public function testSurroundingMarkupSurvivesPopulation(): void
    {
        $html = '<!DOCTYPE html><html><head><title>A page</title></head><body>'
            . '<h1>Heading</h1><p>Some prose.</p>'
            . '<form action="/"><input type="text" name="foo"></form>'
            . '<footer>Footer text</footer></body></html>';

        $out = $this->populate($html, ['foo' => 'bar']);

        $this->assertStringContainsString('<title>A page</title>', $out);
        $this->assertStringContainsString('<h1>Heading</h1>', $out);
        $this->assertStringContainsString('Some prose.', $out);
        $this->assertStringContainsString('Footer text', $out);
    }

    public function testADocumentWithNoFormAtAllIsReturnedIntact(): void
    {
        $html = '<!DOCTYPE html><html><body><p>Nothing to populate.</p></body></html>';

        $this->assertStringContainsString('Nothing to populate.', $this->populate($html, ['foo' => 'bar']));
    }

    public function testAnXhtmlDocumentIsSerializedAsXml(): void
    {
        $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" '
            . '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">'
            . '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
            . '<form action="/"><input type="text" name="foo" /></form></body></html>';

        $out = $this->populate($html, ['foo' => 'bar']);

        $this->assertStringContainsString('value="bar"', $out);
        $this->assertStringContainsString('/>', $out, 'XHTML keeps self-closing tags');
    }

    /** Multiple forms on one page are each populated from the same parameters. */
    public function testEveryMatchingFormOnThePageIsPopulated(): void
    {
        $html = '<!DOCTYPE html><html><body>'
            . '<form action="/"><input type="text" name="foo"></form>'
            . '<form action="/"><input type="text" name="foo"></form>'
            . '</body></html>';

        $out = $this->populate($html, ['foo' => 'bar']);

        $this->assertSame(2, $this->nodeCount($out, '//input[@value="bar"]'));
    }
}
