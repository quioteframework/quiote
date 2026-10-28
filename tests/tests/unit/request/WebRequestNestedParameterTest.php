<?php

use Quiote\Request\RequestParameterStore;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;

/**
 * A nested write changes one key inside a root array and leaves its siblings
 * alone, flattened `root[i][key]` aliases follow the nested value, and pruning
 * cuts runtime arrays down to validated paths unless they were set whole.
 */
class WebRequestNestedParameterTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $body
     * @param list<string> $declared
     */
    private function request(array $body, array $declared = []): WebRequest
    {
        $request = new WebRequest();
        $request->initialize($this->getContext());

        return $request->withParsedBody($body)->declareParameters($declared);
    }

    public function testNestedWriteKeepsThePostedSiblings(): void
    {
        $request = $this->request(['rows' => [['ItemName' => ' a ', 'Qty' => '1']]], ['rows']);

        $request = $request->setParameter('rows[0][ItemName]', 'a');

        $this->assertSame([['ItemName' => 'a', 'Qty' => '1']], $request->getParameter('rows'));
        $this->assertSame('a', $request->getParameter('rows[0][ItemName]'));
    }

    public function testSuccessiveNestedWritesAccumulate(): void
    {
        $request = $this->request(['rows' => [['ItemName' => 'a', 'Qty' => '1'], ['ItemName' => 'b', 'Qty' => '2']]], ['rows']);

        $request = $request
            ->setParameter('rows[0][Qty]', 1)
            ->setParameter('rows[1][Qty]', 2)
            ->setParameter('rows[1][Extra]', 'e');

        $this->assertSame(
            [['ItemName' => 'a', 'Qty' => 1], ['ItemName' => 'b', 'Qty' => 2, 'Extra' => 'e']],
            $request->getParameter('rows'),
        );
    }

    public function testUndeclaredRootIsNotSeededFromTheSubmittedInput(): void
    {
        $request = $this->request(['secret' => ['a' => 'raw', 'b' => 'raw']]);

        $request = $request->setParameter('secret[a]', 'set');

        $this->assertSame(['a' => 'set'], $request->getParameter('secret'));
    }

    public function testNestedWriteWithoutSubmittedRootStartsEmpty(): void
    {
        $request = $this->request([], ['Tags']);

        $request = $request->setParameter('Tags[0]', 'x');

        $this->assertSame(['x'], $request->getParameter('Tags'));
    }

    public function testExistingRuntimeRootIsNotReseeded(): void
    {
        $request = $this->request(['rows' => [['ItemName' => 'posted']]], ['rows'])
            ->setParameter('rows', [['ItemName' => 'set']]);

        $request = $request->setParameter('rows[0][Qty]', 3);

        $this->assertSame([['ItemName' => 'set', 'Qty' => 3]], $request->getParameter('rows'));
    }

    public function testFlattenedAliasFollowsANestedWrite(): void
    {
        $store = (new RequestParameterStore())->withParameter('rows', [['ItemName' => 'old']]);
        $this->assertSame('old', $store->get('rows[0][ItemName]'), 'setting a root list materializes aliases');

        $store = $store->withParameter('rows[0][ItemName]', 'new');

        $this->assertSame('new', $store->get('rows[0][ItemName]'));
        $this->assertSame([['ItemName' => 'new']], $store->get('rows'));
    }

    public function testFlattenedAliasesFollowAReplacedElement(): void
    {
        $store = (new RequestParameterStore())->withParameter('rows', [['ItemName' => 'old', 'Gone' => 'x']]);

        $store = $store->withParameter('rows[0]', ['ItemName' => 'new']);

        $this->assertSame('new', $store->get('rows[0][ItemName]'));
        $this->assertFalse($store->has('rows[0][Gone]'));
    }

    public function testDeepWriteRefreshesTheAliasThatHoldsIt(): void
    {
        $store = (new RequestParameterStore())->withParameter('rows', [['Addr' => ['City' => 'old']]]);

        $store = $store->withParameter('rows[0][Addr][City]', 'new');

        $this->assertSame(['City' => 'new'], $store->get('rows[0][Addr]'));
    }

    public function testResettingARootDropsAliasesOfItsOldValue(): void
    {
        $store = (new RequestParameterStore())->withParameter('rows', [['A' => 1, 'B' => 2]]);

        $store = $store->withParameter('rows', [['A' => 9]]);

        $this->assertSame(9, $store->get('rows[0][A]'));
        $this->assertFalse($store->has('rows[0][B]'));
    }

    public function testSeededRootIsLeftAloneWhenAlreadyPresent(): void
    {
        $store = (new RequestParameterStore())->withParameter('rows', ['kept']);

        $this->assertSame($store, $store->withSeededRoot('rows', ['seed']));
        $this->assertSame($store, $store->withSeededRoot('', ['seed']));
    }

    public function testPruneCutsANestedWriteRootDownToValidatedPaths(): void
    {
        $store = (new RequestParameterStore())
            ->withSeededRoot('rows', [['ItemName' => 'a', 'IsAdmin' => '1'], ['ItemName' => 'b', 'Qty' => 'bad']])
            ->withParameter('rows[0][ItemName]', 'A');

        $pruned = $store->pruneTo(['rows[0][ItemName]', 'rows[1][ItemName]', 'rows[1][Qty]'], ['rows[1][Qty]'], []);

        $this->assertSame([['ItemName' => 'A'], ['ItemName' => 'b']], $pruned->get('rows'));
    }

    public function testPruneSyncsAliasesOfAPrunedRoot(): void
    {
        $store = (new RequestParameterStore())
            ->withUnvalidatedParameter('rows', [['ItemName' => 'a', 'IsAdmin' => '1']])
            ->withUnvalidatedParameter('rows[0][IsAdmin]', '1')
            ->withUnvalidatedParameter('rows[0][ItemName]', 'a')
            ->withEnforcedValidatedParameters(['rows']);

        $pruned = $store->pruneTo(['rows[0][ItemName]'], [], []);

        $this->assertSame([['ItemName' => 'a']], $pruned->get('rows'));
        $this->assertTrue($pruned->has('rows[0][ItemName]'));
        $this->assertFalse($pruned->has('rows[0][IsAdmin]'));
    }

    public function testPruneRematerializesAliasesItDropped(): void
    {
        $store = (new RequestParameterStore())->withParameter('rows', [['ItemName' => 'a', 'IsAdmin' => '1']]);
        $store = $store->withUnvalidatedParameter('rows', $store->get('rows'));

        $pruned = $store->pruneTo(['rows[0][ItemName]'], [], []);

        $this->assertSame('a', $pruned->get('rows[0][ItemName]'));
        $this->assertFalse($pruned->has('rows[0][IsAdmin]'));
    }

    public function testRootSetAsAWholeIsTrustedAsItIs(): void
    {
        $store = (new RequestParameterStore())->withParameter('data', [['Application' => 'orders', 'Enabled' => true]]);

        $pruned = $store->pruneTo([], [], []);

        $this->assertSame([['Application' => 'orders', 'Enabled' => true]], $pruned->get('data'));
    }

    public function testFailedRootIsRemovedWithItsAliases(): void
    {
        $store = (new RequestParameterStore())->withParameter('rows', [['ItemName' => 'a']]);

        $pruned = $store->pruneTo([], ['rows'], []);

        $this->assertFalse($pruned->has('rows'));
        $this->assertFalse($pruned->has('rows[0][ItemName]'));
    }

    public function testAliasWithoutARootKeepsTheNameBasedDecision(): void
    {
        $store = (new RequestParameterStore())
            ->withUnvalidatedParameter('orphan[a]', 'x')
            ->withUnvalidatedParameter('other[b]', 'y');

        $pruned = $store->pruneTo(['orphan[a]'], [], []);

        $this->assertTrue($pruned->has('orphan[a]'));
        $this->assertFalse($pruned->has('other[b]'));
    }

    public function testRemovedOrRevokedRootLosesItsWholeValueStanding(): void
    {
        $store = (new RequestParameterStore())->withParameter('rows', [['a' => 1, 'b' => 2]]);

        $reseeded = $store->withRemovedParameter('rows')->withSeededRoot('rows', [['a' => 1, 'b' => 2]]);
        $this->assertSame([['a' => 1]], $reseeded->pruneTo(['rows[0][a]'], [], [])->get('rows'));

        $revoked = $store->withRevokedParameter('rows')->withSeededRoot('rows', [['a' => 1, 'b' => 2]]);
        $this->assertSame([['a' => 1]], $revoked->pruneTo(['rows[0][a]'], [], [])->get('rows'));
    }

    public function testIntrinsicArraysArePrunedPerPath(): void
    {
        $request = (new WebRequest())
            ->withQueryParams(['q' => ['keep' => '1', 'drop' => '2']])
            ->withParsedBody(['rows' => [['ItemName' => 'a', 'IsAdmin' => '1'], ['Qty' => 'bad']], 'module' => 'M']);

        $pruned = $request->pruneParametersToValidated(['q[keep]', 'rows[0][ItemName]', 'rows[1][Qty]'], ['rows[1][Qty]'], true, 'module', null);

        $this->assertSame(['keep' => '1'], $pruned->getQueryParams()['q']);
        $body = $pruned->getParsedBody();
        $this->assertIsArray($body);
        $this->assertSame([['ItemName' => 'a']], $body['rows']);
        $this->assertSame('M', $body['module'], 'a preserved accessor is untouched');
    }

    public function testIntrinsicArrayValidatedAsAWholeBecomesAnEmptyContainer(): void
    {
        $request = (new WebRequest())->withParsedBody(['rows' => [['ItemName' => 'a']]]);

        $pruned = $request->pruneParametersToValidated(['rows'], [], false, null, null);

        $body = $pruned->getParsedBody();
        $this->assertIsArray($body);
        $this->assertSame([], $body['rows']);
    }
}
