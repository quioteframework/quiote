<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Response\WebResponse;
use Nyholm\Psr7\Factory\Psr17Factory;

class RedirectParityTest extends UnitTestCase
{
    public function testRedirectLocationAndStatusForwarded()
    {
        $web = new WebResponse();
        $psrFactory = new Psr17Factory();
        $psr = $psrFactory->createResponse(200);
    $web->setPsrResponse($psr);
    $web->initialize($this->getContext(), []);

        $web->setRedirect('/new-location', 303);

        ob_start();
        $web->send();
        $out = ob_get_clean();

        $psr2 = $web->getPsrResponse();
        $this->assertNotNull($psr2);
        $this->assertEquals(303, $psr2->getStatusCode());
        $this->assertTrue($psr2->hasHeader('Location'));
        $this->assertStringContainsString('/new-location', $psr2->getHeaderLine('Location'));
    }
}
