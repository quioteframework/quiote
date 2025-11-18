<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Response\AgaviWebResponse;
use Nyholm\Psr7\Factory\Psr17Factory;

class RedirectContentLengthTest extends AgaviUnitTestCase
{
    public function testRedirectDoesNotSendBodyUnlessConfigured()
    {
        $web = new AgaviWebResponse();
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
        $web = new AgaviWebResponse();
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
