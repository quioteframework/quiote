<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Util\FormPopulation\FormFinder;
use Quiote\Util\ParameterHolder;

/**
 * Which forms get populated, and from what.
 *
 * Two selection modes, deliberately different. A ParameterHolder means "the
 * form this request was submitted to", found by comparing each form's action
 * against the request -- a comparison that has to accept the three ways a
 * template may have written that action. An array keyed by form id names
 * specific forms and skips the comparison entirely, because the caller has
 * already said which ones it means.
 */
final class FormFinderTest extends TestCase
{
    private const REQUEST_URI = '/orders/edit';

    private const REQUEST_URL = 'https://example.test/orders/edit';

    private function document(string $bodyHtml): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!DOCTYPE html><html><body>' . $bodyHtml . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function finderFor(DOMDocument $document): FormFinder
    {
        $xpath = new DOMXPath($document);

        return new FormFinder(
            static function (string $expression, ?DOMElement $contextNode = null) use ($xpath): array {
                $nodes = $xpath->query($expression, $contextNode);
                if ($nodes === false) {
                    return [];
                }

                $elements = [];
                foreach ($nodes as $node) {
                    if ($node instanceof DOMElement) {
                        $elements[] = $node;
                    }
                }

                return $elements;
            },
            '',
        );
    }

    private function firstForm(DOMDocument $document, string $id = ''): DOMElement
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query($id === '' ? '//form' : sprintf('//form[@id="%s"]', $id));
        $this->assertNotFalse($nodes);
        $element = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);

        return $element;
    }

    private function holder(): ParameterHolder
    {
        $holder = new ParameterHolder();
        $holder->setParameter('field', 'value');

        return $holder;
    }

    private function dataFor(DOMDocument $document, mixed $populate, string $baseHref = '', string $id = ''): ?ParameterHolder
    {
        return $this->finderFor($document)->dataFor(
            $this->firstForm($document, $id),
            $populate,
            self::REQUEST_URI,
            self::REQUEST_URL,
            $baseHref,
            null,
        );
    }

    // --- matching a form's action against the request -------------------------

    public function testAFormPostingToTheAbsoluteRequestUrlMatches(): void
    {
        $document = $this->document('<form action="' . self::REQUEST_URL . '"></form>');

        $this->assertNotNull($this->dataFor($document, $this->holder()));
    }

    public function testAFormPostingToTheRootRelativeRequestPathMatches(): void
    {
        $document = $this->document('<form action="' . self::REQUEST_URI . '"></form>');

        $this->assertNotNull($this->dataFor($document, $this->holder()));
    }

    public function testAFormPostingSomewhereElseDoesNotMatch(): void
    {
        $document = $this->document('<form action="/orders/list"></form>');

        $this->assertNull($this->dataFor($document, $this->holder()));
    }

    /** A fragment never reaches the server, so it plays no part in the comparison. */
    public function testAFragmentInTheActionIsIgnored(): void
    {
        $document = $this->document('<form action="' . self::REQUEST_URI . '#section-2"></form>');

        $this->assertNotNull($this->dataFor($document, $this->holder()));
    }

    public function testSurroundingWhitespaceInTheActionIsIgnored(): void
    {
        $document = $this->document('<form action="  ' . self::REQUEST_URI . '  "></form>');

        $this->assertNotNull($this->dataFor($document, $this->holder()));
    }

    /** Dot segments and doubled slashes are collapsed the way a browser would. */
    public function testADottedPathIsNormalisedBeforeComparison(): void
    {
        foreach (['/orders/./edit', '/orders/other/../edit', '/orders//edit'] as $action) {
            $document = $this->document('<form action="' . $action . '"></form>');

            $this->assertNotNull($this->dataFor($document, $this->holder()), $action . ' must match');
        }
    }

    // --- base href ---------------------------------------------------------------

    /**
     * A relative action resolves against <base href>, so a template that wrote
     * "edit" under a base of the orders directory still names this request.
     */
    public function testARelativeActionResolvesAgainstTheBaseHref(): void
    {
        $document = $this->document('<form action="edit"></form>');

        $this->assertNotNull($this->dataFor($document, $this->holder(), 'https://example.test/orders/'));
    }

    public function testARelativeActionUnderADifferentBaseDoesNotMatch(): void
    {
        $document = $this->document('<form action="edit"></form>');

        $this->assertNull($this->dataFor($document, $this->holder(), 'https://example.test/invoices/'));
    }

    /** With no base, a bare relative action has nothing to resolve against. */
    public function testARelativeActionWithoutABaseDoesNotMatch(): void
    {
        $document = $this->document('<form action="edit"></form>');

        $this->assertNull($this->dataFor($document, $this->holder()));
    }

    /**
     * A *leading* "./" is not normalised: every dot-segment pattern requires a
     * slash before the dot, so "./edit" reaches the comparison intact and does
     * not match, where a browser would resolve it exactly as "edit". Pinned as
     * the behaviour that exists rather than the behaviour one might expect --
     * a template writing action="./edit" under a base href goes unpopulated.
     */
    public function testALeadingDotSegmentIsNotResolvedAgainstTheBase(): void
    {
        $document = $this->document('<form action="./edit"></form>');

        $this->assertNull($this->dataFor($document, $this->holder(), 'https://example.test/orders/'));
    }

    /** An interior dot segment is normalised, so the base still applies. */
    public function testAnInteriorDotSegmentIsResolvedAgainstTheBase(): void
    {
        $document = $this->document('<form action="other/../edit"></form>');

        $this->assertNotNull($this->dataFor($document, $this->holder(), 'https://example.test/orders/'));
    }

    // --- selecting forms by id -----------------------------------------------------

    public function testFormsAreFoundByTheIdsTheCallerNamed(): void
    {
        $document = $this->document('<form id="first"></form><form id="second"></form><form id="third"></form>');

        $forms = $this->finderFor($document)->find(['first' => $this->holder(), 'third' => $this->holder()], []);

        $this->assertCount(2, $forms);
        $this->assertSame(['first', 'third'], array_map(static fn(DOMElement $f): string => $f->getAttribute('id'), $forms));
    }

    /**
     * A form marked true is visited first, whatever its position in the
     * document: errors are inserted as forms are visited, and the re-populated
     * form has to claim its own before the others are offered them.
     */
    public function testARePopulatedFormIsVisitedBeforeTheOthers(): void
    {
        $document = $this->document('<form id="first"></form><form id="second"></form>');

        $forms = $this->finderFor($document)->find(['first' => $this->holder(), 'second' => true], []);

        $this->assertSame(['second', 'first'], array_map(static fn(DOMElement $f): string => $f->getAttribute('id'), $forms));
    }

    public function testAnIdThatMatchesNoFormIsSkipped(): void
    {
        $document = $this->document('<form id="present"></form>');

        $forms = $this->finderFor($document)->find(['absent' => $this->holder(), 'present' => $this->holder()], []);

        $this->assertCount(1, $forms);
        $this->assertSame('present', $forms[0]->getAttribute('id'));
    }

    public function testNonStringKeysAreIgnored(): void
    {
        $document = $this->document('<form id="named"></form>');

        $forms = $this->finderFor($document)->find([0 => $this->holder(), 'named' => $this->holder()], []);

        $this->assertCount(1, $forms);
    }

    /** Selection by id skips the action comparison: the caller already said which form. */
    public function testAFormNamedByIdIsPopulatedWhateverItsAction(): void
    {
        $document = $this->document('<form id="target" action="https://elsewhere.test/somewhere"></form>');
        $holder = $this->holder();

        $this->assertSame($holder, $this->dataFor($document, ['target' => $holder], id: 'target'));
    }

    public function testAFormWhoseIdWasNotNamedIsNotPopulated(): void
    {
        $document = $this->document('<form id="other" action="' . self::REQUEST_URI . '"></form>');

        $this->assertNull($this->dataFor($document, ['target' => $this->holder()], id: 'other'));
    }

    public function testAFormWithNoIdIsNotPopulatedInIdMode(): void
    {
        $document = $this->document('<form action="' . self::REQUEST_URI . '"></form>');

        $this->assertNull($this->dataFor($document, ['target' => $this->holder()]));
    }

    /** An id naming something that is not data cannot populate a form. */
    public function testAnIdMappedToSomethingOtherThanAHolderIsNotUsed(): void
    {
        $document = $this->document('<form id="target"></form>');

        $this->assertNull($this->dataFor($document, ['target' => 'not a parameter holder'], id: 'target'));
    }

    // --- non-form containers -------------------------------------------------------

    /**
     * forms_xpath can select a container that is not a <form>, which has no
     * action to compare, so it is populated from whatever data is on offer.
     */
    public function testANonFormContainerIsPopulatedFromTheGivenHolder(): void
    {
        $document = $this->document('<div id="pseudo-form"></div>');
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//div');
        $this->assertNotFalse($nodes);
        $div = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $div);

        $holder = $this->holder();
        $data = $this->finderFor($document)->dataFor($div, $holder, self::REQUEST_URI, self::REQUEST_URL, '', null);

        $this->assertSame($holder, $data);
    }

    public function testANonFormContainerFallsBackToTheRequestHolder(): void
    {
        $document = $this->document('<div id="pseudo-form"></div>');
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//div');
        $this->assertNotFalse($nodes);
        $div = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $div);

        $fallback = $this->holder();
        $data = $this->finderFor($document)->dataFor(
            $div,
            ['some' => 'array'],
            self::REQUEST_URI,
            self::REQUEST_URL,
            '',
            $fallback,
        );

        $this->assertSame($fallback, $data);
    }

    // --- xpath selection --------------------------------------------------------------

    public function testWithoutAnIdMapEveryFormTheXpathSelectsIsReturned(): void
    {
        $document = $this->document('<form id="a"></form><form id="b"></form>');

        $forms = $this->finderFor($document)->find($this->holder(), ['forms_xpath' => '//form']);

        $this->assertCount(2, $forms);
    }

    public function testANonStringFormsXpathIsTreatedAsEmpty(): void
    {
        $document = $this->document('<form id="a"></form>');

        $this->assertSame([], $this->finderFor($document)->find($this->holder(), ['forms_xpath' => 42]));
    }
}
