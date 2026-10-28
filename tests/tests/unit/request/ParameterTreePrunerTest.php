<?php

use PHPUnit\Framework\TestCase;
use Quiote\Request\ParameterTreePruner;

/**
 * Inside a root parameter only the paths validation vouched for survive: a
 * named path keeps its node, an ancestor survives as a container, a failed
 * path removes its subtree, and an array validated as a whole stays an empty
 * container.
 */
class ParameterTreePrunerTest extends TestCase
{
    private const ROWS = [
        ['ItemName' => 'a', 'Qty' => '1', 'Comment' => 'x'],
        ['ItemName' => 'b', 'Qty' => 'bad', 'IsAdmin' => '1'],
    ];

    public function testKeepsOnlyNamedLeavesInsideEachElement(): void
    {
        $pruner = new ParameterTreePruner(
            ['rows[0][ItemName]', 'rows[0][Qty]', 'rows[1][ItemName]', 'rows[1][Qty]'],
            [],
        );

        $this->assertSame(
            [['ItemName' => 'a', 'Qty' => '1'], ['ItemName' => 'b', 'Qty' => 'bad']],
            $pruner->prune('rows', self::ROWS),
        );
    }

    public function testFailedLeafIsRemovedEvenThoughItIsAlsoKept(): void
    {
        $pruner = new ParameterTreePruner(
            ['rows[0][ItemName]', 'rows[0][Qty]', 'rows[1][ItemName]', 'rows[1][Qty]'],
            ['rows[1][Qty]'],
        );

        $this->assertSame(
            [['ItemName' => 'a', 'Qty' => '1'], ['ItemName' => 'b']],
            $pruner->prune('rows', self::ROWS),
        );
    }

    public function testFailedAncestorRemovesTheWholeElement(): void
    {
        $pruner = new ParameterTreePruner(['rows[0][ItemName]', 'rows[1][ItemName]'], ['rows[1]']);

        $this->assertSame([['ItemName' => 'a']], $pruner->prune('rows', self::ROWS));
    }

    public function testElementWithNothingKeptDisappears(): void
    {
        $pruner = new ParameterTreePruner(['rows[0][ItemName]'], []);

        $this->assertSame([['ItemName' => 'a']], $pruner->prune('rows', self::ROWS));
    }

    public function testRootValidatedAsAWholeKeepsOnlyAnEmptyContainer(): void
    {
        $pruner = new ParameterTreePruner(['rows'], []);

        $this->assertSame([], $pruner->prune('rows', self::ROWS));
        $this->assertSame(['rows'], $pruner->roots());
    }

    public function testNamedArrayElementKeepsAnEmptyContainerOfItsOwn(): void
    {
        $pruner = new ParameterTreePruner(['rows[0]'], []);

        $this->assertSame([[]], $pruner->prune('rows', self::ROWS));
    }

    public function testEmptySegmentMatchesEveryKey(): void
    {
        $pruner = new ParameterTreePruner(['rows[][ItemName]'], []);

        $this->assertSame([['ItemName' => 'a'], ['ItemName' => 'b']], $pruner->prune('rows', self::ROWS));
    }

    public function testWildcardAndSpecificPathsCombine(): void
    {
        $pruner = new ParameterTreePruner(['rows[][ItemName]', 'rows[1][Qty]'], []);

        $this->assertSame(
            [['ItemName' => 'a'], ['ItemName' => 'b', 'Qty' => 'bad']],
            $pruner->prune('rows', self::ROWS),
        );
    }

    public function testScalarListUnderAWildcardSurvives(): void
    {
        $pruner = new ParameterTreePruner(['tags[]'], []);

        $this->assertSame(['x', 'y'], $pruner->prune('tags', ['x', 'y']));
    }

    public function testDeepNestingAndStringKeys(): void
    {
        $value = ['u' => ['addr' => ['city' => 'Turku', 'zip' => '20100', 'hack' => '1']]];
        $pruner = new ParameterTreePruner(['user[u][addr][city]', 'user[u][addr][zip]'], []);

        $this->assertSame(['u' => ['addr' => ['city' => 'Turku', 'zip' => '20100']]], $pruner->prune('user', $value));
    }

    public function testNumericKeysMatchTheirStringSpelling(): void
    {
        $pruner = new ParameterTreePruner(['m[10][x]'], []);

        $this->assertSame([10 => ['x' => 1]], $pruner->prune('m', [10 => ['x' => 1, 'y' => 2]]));
    }

    public function testObjectLeafIsKeptWhenNamed(): void
    {
        $object = new \stdClass();
        $pruner = new ParameterTreePruner(['rows[0][Ref]'], []);

        $this->assertSame([['Ref' => $object]], $pruner->prune('rows', [['Ref' => $object, 'Raw' => '1']]));
    }

    public function testRootWithoutAnyKeptPathPrunesToEmpty(): void
    {
        $pruner = new ParameterTreePruner([], []);

        $this->assertSame([], $pruner->prune('rows', self::ROWS));
        $this->assertSame([], $pruner->roots());
    }

    public function testRootsListsEveryRootAKeptPathReaches(): void
    {
        $pruner = new ParameterTreePruner(['rows[0][ItemName]', 'name', 'tags[]'], ['other[0]']);

        $this->assertSame(['rows', 'name', 'tags'], $pruner->roots());
    }

    public function testRelativeAndUnparsablePathsAreIgnored(): void
    {
        $pruner = new ParameterTreePruner(['[0][x]', 'rows[[bad]', '', 'rows[0][ItemName]'], ['rows]']);

        $this->assertSame(['rows'], $pruner->roots());
        $this->assertSame([['ItemName' => 'a']], $pruner->prune('rows', self::ROWS));
    }
}
