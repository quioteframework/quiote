<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Exception\QuioteException;
use Quiote\Util\Toolkit;

/**
 * `overloadHelper()` picks which of several same-named method signatures a
 * call matches, since PHP has no overloading of its own. Selection is by
 * argument count first, then by the type of each argument in order.
 */
final class ToolkitOverloadHelperTest extends TestCase
{
    /** @return array<int, array{parameters: array<int, string>, name: string}> */
    private function definitions(): array
    {
        return [
            ['parameters' => ['integer'], 'name' => 'takesInt'],
            ['parameters' => ['string'], 'name' => 'takesString'],
            ['parameters' => ['string', 'integer'], 'name' => 'takesStringAndInt'],
        ];
    }

    /**
     * The definition that matches is the one returned, wherever it sits in
     * the list. Returning the last-examined definition instead happens to be
     * right only when the match comes last, so an integer picking the string
     * signature is the shape this guards.
     */
    public function testTheMatchingDefinitionIsReturnedRegardlessOfItsPosition(): void
    {
        $this->assertSame('takesInt', Toolkit::overloadHelper($this->definitions(), [42]));
        $this->assertSame('takesString', Toolkit::overloadHelper($this->definitions(), ['a string']));
    }

    /** Reversing the declaration order must not change which one matches. */
    public function testTheDeclarationOrderDoesNotAffectSelection(): void
    {
        $reversed = array_reverse($this->definitions());

        $this->assertSame('takesInt', Toolkit::overloadHelper($reversed, [42]));
        $this->assertSame('takesString', Toolkit::overloadHelper($reversed, ['a string']));
    }

    /** With one candidate at that arity, the types are not consulted at all. */
    public function testASoleCandidateAtThatArityIsChosenDirectly(): void
    {
        $this->assertSame(
            'takesStringAndInt',
            Toolkit::overloadHelper($this->definitions(), ['a string', 7]),
        );
    }

    public function testArgumentCountIsWhatNarrowsTheCandidatesFirst(): void
    {
        $definitions = [
            ['parameters' => ['string'], 'name' => 'one'],
            ['parameters' => ['string', 'string'], 'name' => 'two'],
            ['parameters' => ['string', 'string', 'string'], 'name' => 'three'],
        ];

        $this->assertSame('one', Toolkit::overloadHelper($definitions, ['a']));
        $this->assertSame('two', Toolkit::overloadHelper($definitions, ['a', 'b']));
        $this->assertSame('three', Toolkit::overloadHelper($definitions, ['a', 'b', 'c']));
    }

    /** Types are matched by prefix, so "int" accepts what gettype() calls "integer". */
    public function testATypeIsMatchedByPrefix(): void
    {
        $definitions = [
            ['parameters' => ['int'], 'name' => 'takesInt'],
            ['parameters' => ['str'], 'name' => 'takesString'],
        ];

        $this->assertSame('takesInt', Toolkit::overloadHelper($definitions, [42]));
        $this->assertSame('takesString', Toolkit::overloadHelper($definitions, ['a string']));
    }

    public function testEachArgumentPositionIsChecked(): void
    {
        $definitions = [
            ['parameters' => ['string', 'integer'], 'name' => 'stringThenInt'],
            ['parameters' => ['integer', 'string'], 'name' => 'intThenString'],
        ];

        $this->assertSame('stringThenInt', Toolkit::overloadHelper($definitions, ['a', 1]));
        $this->assertSame('intThenString', Toolkit::overloadHelper($definitions, [1, 'a']));
    }

    // --- no usable match ----------------------------------------------------

    public function testAnArgumentCountNoDefinitionDeclaresIsReported(): void
    {
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('parameter count 4');

        Toolkit::overloadHelper($this->definitions(), ['a', 'b', 'c', 'd']);
    }

    public function testArgumentTypesNoDefinitionAcceptsAreReported(): void
    {
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage("couldn't find a matching method");

        Toolkit::overloadHelper($this->definitions(), [[1, 2]]);
    }

    /**
     * An ambiguous call is refused rather than resolved arbitrarily: two
     * definitions accepting the same arguments is a declaration mistake, and
     * picking one silently would hide it.
     */
    public function testAnAmbiguousCallIsRefusedAndSaysHowManyMatched(): void
    {
        $definitions = [
            ['parameters' => ['string'], 'name' => 'first'],
            ['parameters' => ['string'], 'name' => 'second'],
        ];

        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('found 2 matching methods');

        Toolkit::overloadHelper($definitions, ['a string']);
    }

    public function testNoDefinitionsAtAllIsReportedAsAnArityMismatch(): void
    {
        $this->expectException(QuioteException::class);
        $this->expectExceptionMessage('parameter count 1');

        Toolkit::overloadHelper([], ['a string']);
    }
}
