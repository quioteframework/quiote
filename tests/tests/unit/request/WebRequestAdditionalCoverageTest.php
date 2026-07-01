<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Request\WebRequest;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Nyholm\Psr7\Stream;

/**
 * Additional low-level coverage tests for WebRequest targeting
 * bracket path mutation, append semantics, attribute manipulation,
 * PSR-7 immutability helpers, and trailing [] access logic.
 */
class WebRequestAdditionalCoverageTest extends UnitTestCase
{
    private function newRequest(array $server = [], array $query = [], array $body = [], array $headers = []): WebRequest
    {
        $wr = new WebRequest(
            $server['REQUEST_METHOD'] ?? 'GET',
            ($server['REQUEST_SCHEME'] ?? 'http') . '://' . ($server['HTTP_HOST'] ?? 'example.test') . ($server['REQUEST_URI'] ?? '/'),
            $headers,
            null,
            '1.1',
            $server
        );
        $wr->initialize($this->getContext());
        $wr = $wr->withQueryParams($query)->withParsedBody($body);
        return $wr;
    }

    public function testSetParameterBracketBuildsNestedStructure(): void
    {
        $wr = $this->newRequest();
        $wr->setParameter('user[profile][name]', 'alice');
        // Whitelist both root and bracket path for strict validation
        $wr->enforceValidatedParameters(['user','user[profile][name]']);
        $this->assertSame('alice', $wr->getParameter('user[profile][name]'));
        // Ensure full bracket key not stored separately at root
        $all = $wr->getParameters('runtime');
        $this->assertArrayHasKey('user', $all);
        $this->assertArrayNotHasKey('user[profile][name]', $all, 'Bracket path should not be flattened into explicit key');
        $this->assertSame('alice', $all['user']['profile']['name']);
    }

    public function testAppendParameterCreatesArrayAndAppends(): void
    {
        $wr = $this->newRequest();
        $wr->appendParameter('list', 'a');
        $wr->appendParameter('list', 'b');
        $wr->enforceValidatedParameters(['list']);
        $vals = $wr->getParameter('list');
        $this->assertSame(['a','b'], $vals);
    }

    public function testAppendListAttributeAndRemoval(): void
    {
        $wr = $this->newRequest();
        $wr = $wr->appendListAttribute('assets', 'a.css');
        $wr = $wr->appendListAttribute('assets', 'b.css');
        $this->assertTrue($wr->hasAttribute('assets'));
        $attrs = $wr->getAttributes();
        $this->assertSame(['a.css','b.css'], $attrs['assets']);
        $wr2 = $wr->withoutAttribute('assets');
        $this->assertFalse($wr2->hasAttribute('assets'));
        $this->assertTrue($wr->hasAttribute('assets'), 'Original instance should remain unchanged (immutability)');
    }

    public function testHeaderManipulationImmutability(): void
    {
        $wr = $this->newRequest([], [], [], ['X-Test' => ['one']]);
        $this->assertTrue($wr->hasHeader('X-Test'));
        $wr2 = $wr->withAddedHeader('X-Test', 'two');
        $this->assertSame(['one'], $wr->getHeader('X-Test'));
        $this->assertSame(['one','two'], $wr2->getHeader('X-Test'));
        $wr3 = $wr2->withoutHeader('X-Test');
        $this->assertFalse($wr3->hasHeader('X-Test'));
        $this->assertSame(['one','two'], $wr2->getHeader('X-Test'));
    }

    public function testWithQueryParamsCloneIndependence(): void
    {
        $wr = $this->newRequest([], ['a' => '1'], ['b' => '2']);
        $wr->enforceValidatedParameters(['a','b']);
        $this->assertSame('1', $wr->getParameter('a'));
        $next = $wr->withQueryParams(['a' => '9']);
        // In clone, a changed; original unchanged
        $next->enforceValidatedParameters(['a','b']); // ensure whitelist copied or reinforced
        $this->assertSame('9', $next->getParameter('a'));
        $this->assertSame('1', $wr->getParameter('a'));
        $this->assertSame('2', $wr->getParameter('b'));
    }

    public function testTrailingBracketArrayAccess(): void
    {
        $wr = $this->newRequest([], [], ['tags' => ['x','y']]);
        $wr->enforceValidatedParameters(['tags','tags[]']);
        $tags = $wr->getParameter('tags[]');
        $this->assertSame(['x','y'], $tags);
    }
}
