<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Response\WebResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Http\PsrResponseAdapter;

class PsrToQuioteRoundTripTest extends UnitTestCase
{
    /**
     * getPsrResponse() is nullable in general (no PSR response attached
     * yet), but every call in this test happens right after
     * setPsrResponse(), so a null here would mean WebResponse failed to
     * retain what it was just given.
     */
    private function requirePsrResponse(WebResponse $web): \Psr\Http\Message\ResponseInterface
    {
        $psr = $web->getPsrResponse();
        if ($psr === null) {
            throw new \RuntimeException('Expected WebResponse to retain the attached PSR response.');
        }
        return $psr;
    }

    public function testPsrMutationReflectedIntoQuioteResponse(): void
    {
        $web = new WebResponse();
        $psrFactory = new Psr17Factory();
        $psr = $psrFactory->createResponse(200)->withHeader('X-From-PSR','x');

        // Attach PSR response that already has headers
        $web->setPsrResponse($psr);

        // WebResponse should have imported the body/status on attach; headers are accessible via getPsrResponse()
        $this->assertEquals(200, $this->requirePsrResponse($web)->getStatusCode());
        $this->assertTrue($this->requirePsrResponse($web)->hasHeader('X-From-PSR'));

        // Now mutate PSR response via adapter and ensure WebResponse can reflect changes when re-wrapped
        $psr2 = $this->requirePsrResponse($web)->withHeader('X-New','y');
        $web->setPsrResponse($psr2);
        $this->assertTrue($this->requirePsrResponse($web)->hasHeader('X-New'));
    }
}
