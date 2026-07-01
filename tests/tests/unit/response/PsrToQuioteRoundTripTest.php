<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Response\WebResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Http\PsrResponseAdapter;

class PsrToQuioteRoundTripTest extends UnitTestCase
{
    public function testPsrMutationReflectedIntoQuioteResponse()
    {
        $web = new WebResponse();
        $psrFactory = new Psr17Factory();
        $psr = $psrFactory->createResponse(200)->withHeader('X-From-PSR','x');

        // Attach PSR response that already has headers
        $web->setPsrResponse($psr);

        // WebResponse should have imported the body/status on attach; headers are accessible via getPsrResponse()
        $this->assertEquals(200, $web->getPsrResponse()->getStatusCode());
        $this->assertTrue($web->getPsrResponse()->hasHeader('X-From-PSR'));

        // Now mutate PSR response via adapter and ensure WebResponse can reflect changes when re-wrapped
        $psr2 = $web->getPsrResponse()->withHeader('X-New','y');
        $web->setPsrResponse($psr2);
        $this->assertTrue($web->getPsrResponse()->hasHeader('X-New'));
    }
}
