<?php

use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;

/**
 * Covers WebRequest::withParameters() (and the RequestParameterStore bulk path
 * behind it): a single-clone bulk equivalent of calling setParameter() per key.
 * The bulk result must be byte-for-byte equivalent to the per-key loop it
 * replaces in ActionExecutor, including auto-whitelisting and bracket-path /
 * root-array materialization semantics.
 */
class WebRequestWithParametersTest extends UnitTestCase
{
    public function testWithParametersSetsAndWhitelistsAllKeys(): void
    {
        $req = (new WebRequest())->enforceValidatedParameters([]);
        $req = $req->withParameters(['a' => '1', 'b' => 2, 'c' => 'three']);

        $this->assertSame('1', $req->getParameter('a'));
        $this->assertSame(2, $req->getParameter('b'));
        $this->assertSame('three', $req->getParameter('c'));

        $keys = $req->getRuntimeParameterKeys();
        $this->assertContains('a', $keys);
        $this->assertContains('b', $keys);
        $this->assertContains('c', $keys);
    }

    public function testWithParametersIsEquivalentToPerKeyLoop(): void
    {
        $params = [
            'name' => 'value',
            'num' => 42,
            'arr' => ['x' => 1, 'y' => 2],
            'data' => [['field1' => 'v1', 'field2' => 'v2']],
        ];

        $loop = (new WebRequest())->enforceValidatedParameters([]);
        foreach ($params as $k => $v) {
            $loop = $loop->setParameter($k, $v);
        }

        $bulk = (new WebRequest())->enforceValidatedParameters([])->withParameters($params);

        $this->assertSame($loop->getParameters('runtime'), $bulk->getParameters('runtime'));
        $this->assertSame($loop->getRuntimeParameterKeys(), $bulk->getRuntimeParameterKeys());
    }

    public function testWithParametersMaterializesRootArrayBracketPaths(): void
    {
        $req = (new WebRequest())->enforceValidatedParameters([]);
        $req = $req->withParameters([
            'data' => [['field1' => 'value1', 'field2' => 'value2']],
        ]);

        $data = $req->getParameter('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey(0, $data);
        $this->assertIsArray($data[0]);
        $this->assertSame('value1', $data[0]['field1']);
        // The materialized bracket path is present in the runtime store (available
        // to validators), mirroring setParameter()'s root-array materialization.
        $this->assertContains('data[0][field1]', $req->getRuntimeParameterKeys());
    }

    public function testWithParametersSupportsBracketNotationKeys(): void
    {
        $req = (new WebRequest())->enforceValidatedParameters([]);
        $req = $req->withParameters(['item[0][name]' => 'widget']);

        $item = $req->getParameter('item');
        $this->assertIsArray($item);
        $this->assertArrayHasKey(0, $item);
        $this->assertIsArray($item[0]);
        $this->assertSame('widget', $item[0]['name']);
    }

    public function testWithParametersLeavesOriginalRequestUnchanged(): void
    {
        $original = (new WebRequest())->enforceValidatedParameters([]);
        $modified = $original->withParameters(['x' => 'y']);

        $this->assertNotSame($original, $modified);
        $this->assertNotContains('x', $original->getRuntimeParameterKeys());
        $this->assertContains('x', $modified->getRuntimeParameterKeys());
    }

    public function testWithParametersEmptyArrayReturnsSameInstance(): void
    {
        $req = (new WebRequest())->enforceValidatedParameters([]);
        $this->assertSame($req, $req->withParameters([]));
    }
}
