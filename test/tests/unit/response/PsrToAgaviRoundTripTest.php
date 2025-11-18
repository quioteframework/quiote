<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Response\AgaviWebResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Agavi\Http\PsrResponseAdapter;

class PsrToAgaviRoundTripTest extends AgaviUnitTestCase
{
    public function testPsrMutationReflectedIntoAgaviResponse()
    {
        $web = new AgaviWebResponse();
        $psrFactory = new Psr17Factory();
        $psr = $psrFactory->createResponse(200)->withHeader('X-From-PSR','x');

        // Attach PSR response that already has headers
        $web->setPsrResponse($psr);

        // AgaviWebResponse should have imported the body/status on attach; headers are accessible via getPsrResponse()
        $this->assertEquals(200, $web->getPsrResponse()->getStatusCode());
        $this->assertTrue($web->getPsrResponse()->hasHeader('X-From-PSR'));

        // Now mutate PSR response via adapter and ensure AgaviWebResponse can reflect changes when re-wrapped
        $psr2 = $web->getPsrResponse()->withHeader('X-New','y');
        $web->setPsrResponse($psr2);
        $this->assertTrue($web->getPsrResponse()->hasHeader('X-New'));
    }
}
