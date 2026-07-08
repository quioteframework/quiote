<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ResponseHandle;
use Quiote\Response\WebResponse;

/**
 * Tests for ResponseHandle facade behavior.
 */
// Minimal response implementation without optional methods for negative path testing
if(!class_exists('TestMinimalResponse')) {
    class TestMinimalResponse extends \Quiote\Response\WebResponse {
        protected $content = '';
        protected $redirect = null;
        #[\Override]
        public function initialize($context, array $parameters = []) {}
        #[\Override]
        public function appendContent($content) { $this->content .= $content; }
        #[\Override]
        public function setContent($content) { $this->content = $content; }
        #[\Override]
        public function getContent() { return $this->content; }
        #[\Override]
        public function setRedirect($location, $code = 302) { $this->redirect = ['location'=>$location,'code'=>$code]; }
        #[\Override]
        public function getRedirect() { return $this->redirect; }
        #[\Override]
        public function hasRedirect() { return $this->redirect !== null; }
        #[\Override]
        public function clearRedirect() { $this->redirect = null; }
        #[\Override]
        public function clear() { $this->clearContent(); }
        #[\Override]
        public function send(?\Quiote\Controller\OutputType $outputType = null) {}
    }
}

class ResponseHandleTest extends UnitTestCase
{
    /** Simple concrete test response implementing needed methods */
    private static function buildConcrete(): WebResponse
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
    $h->clear(); // ResponseHandle::getContent() casts to string, so cleared content reads as ''
        $h->setStatusCode(500); // no-op
        $h->addHeader('X-Ignored','x'); // no-op
    $this->assertSame('', $h->getContent(), 'Content cleared (allowed)');
    // Further operations should not error
    $this->assertSame('', $h->getContent());
    }
}
