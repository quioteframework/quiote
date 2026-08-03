<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Response\WebResponse;
use Nyholm\Psr7\Factory\Psr17Factory;

class RedirectContentLengthTest extends UnitTestCase
{
    public function testRedirectDoesNotSendBodyUnlessConfigured(): void
    {
        $web = new WebResponse();
        $psrFactory = new Psr17Factory();
        $psr = $psrFactory->createResponse(200);
        $web->setPsrResponse($psr);
        $web->initialize($this->getContext(), []);

        // default behaviour: redirect present -> no body staged and Content-Length set to 0
        $web->setRedirect('/out', 302);
        ob_start();
        $web->send();
        $out = ob_get_clean();

        $psr2 = $web->getPsrResponse();
        $this->assertNotNull($psr2);
        $this->assertEquals(0, $psr2->getBody()->getSize() ?: 0);
        $this->assertEquals('', $out);
        $staged = $web->getStagedResponse();
        $this->assertNotNull($staged);
        $this->assertSame('', (string) $staged->getBody());
        $this->assertSame('0', $staged->getHeaderLine('Content-Length'));
    }

    public function testRedirectSendsContentWhenConfigured(): void
    {
        $web = new WebResponse();
        $web->setParameter('send_redirect_content', true);
        $web->initialize($this->getContext(), []);
        $psrFactory = new Psr17Factory();
        $psr = $psrFactory->createResponse(200);
        $web->setPsrResponse($psr);

        $web->setRedirect('/out', 302);
        $web->setContent('body-redirect');
        ob_start();
        $web->send();
        $out = ob_get_clean();

        $psr2 = $web->getPsrResponse();
        $this->assertNotNull($psr2);
        $this->assertEquals('body-redirect', (string) $psr2->getBody());
        $this->assertSame('', $out, 'the body is staged for the emitter, never echoed');
        $staged = $web->getStagedResponse();
        $this->assertNotNull($staged);
        $this->assertSame('body-redirect', (string) $staged->getBody());
    }
}
