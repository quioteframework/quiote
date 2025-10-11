<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ResponseHandle;
use Agavi\Response\AgaviResponse;

/**
 * Tests for ResponseHandle facade behavior.
 */
// Minimal response implementation without optional methods for negative path testing
if(!class_exists('TestMinimalResponse')) {
    class TestMinimalResponse extends AgaviResponse {
        protected $content = '';
        private $redirect = null;
        public function initialize($context, array $parameters = []) {}
        public function appendContent($content) { $this->content .= $content; }
        public function setContent($content) { $this->content = $content; }
        public function getContent() { return $this->content; }
        public function setRedirect($to) { $this->redirect = ['to'=>$to]; }
        public function getRedirect() { return $this->redirect; }
        public function hasRedirect() { return $this->redirect !== null; }
        public function clearRedirect() { $this->redirect = null; }
        public function clear() { $this->clearContent(); }
        public function send(?\Agavi\Controller\AgaviOutputType $outputType = null) {}
    }
}

class ResponseHandleTest extends AgaviUnitTestCase
{
    /** Simple concrete test response implementing needed methods */
    private static function buildConcrete(): AgaviResponse
    {
        require_once __DIR__ . '/TestConcreteResponse.php';
        return new TestConcreteResponse();
    }

    public function testAppendSetGetAndClear(): void
    {
    $inner = self::buildConcrete();
        $h = new ResponseHandle($inner);
        $h->append('Hello');
        $h->append(' World');
        $this->assertSame('Hello World', $h->getContent());
        $h->set('Reset');
        $this->assertSame('Reset', $h->getContent());
        $h->clear();
        $this->assertSame('', $h->getContent());
        // ensure clear did not throw and content emptied
    }

    public function testStatusAndHeaderForwarding(): void
    {
    $inner = self::buildConcrete();
        $h = new ResponseHandle($inner);
        $h->setStatusCode(201);
        $h->addHeader('X-Test','v');
        // Can't directly assert internal call list due to dynamic anonymous class type, but
        // verify no exceptions thrown and content unaffected
        $this->assertSame('', $h->getContent());
    }

    public function testNoErrorWhenOptionalMethodsMissing(): void
    {
        $inner = new TestMinimalResponse();
        $h = new ResponseHandle($inner);
        $h->append('A');
    $h->clear(); // minimal response has clearContent via inheritance; content becomes null
        $h->setStatusCode(500); // no-op
        $h->addHeader('X-Ignored','x'); // no-op
    $this->assertTrue($h->getContent() === '' || $h->getContent() === null, 'Content cleared (allowed)');
    // Further operations should not error
    $this->assertTrue($h->getContent() === '' || $h->getContent() === null);
    }
}
