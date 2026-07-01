<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Response\WebResponse;
use Nyholm\Psr7\Factory\Psr17Factory;

class RedirectContentLengthTest extends UnitTestCase
{
    public function testRedirectDoesNotSendBodyUnlessConfigured()
    {
        $web = new WebResponse();
        $psrFactory = new Psr17Factory();
        $psr = $psrFactory->createResponse(200);
    $web->setPsrResponse($psr);
    $web->initialize($this->getContext(), []);

        // default behaviour: redirect present -> no content echoed and Content-Length set to 0
        $web->setRedirect('/out', 302);
        ob_start();
        $web->send();
        $out = ob_get_clean();

        $psr2 = $web->getPsrResponse();
        $this->assertEquals(0, $psr2->getBody()->getSize() ?: 0);
        $this->assertEquals('', $out);
    }

    public function testRedirectSendsContentWhenConfigured()
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
        $this->assertEquals('body-redirect', (string)$psr2->getBody());
        $this->assertEquals('body-redirect', $out);
    }
}
