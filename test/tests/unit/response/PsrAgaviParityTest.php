<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Response\AgaviWebResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Agavi\Http\SimpleStream;

class PsrAgaviParityTest extends AgaviUnitTestCase
{
    public function testStatusHeaderCookieAndBodyForwarding()
    {
        $web = new AgaviWebResponse();

        // Build a PSR response to attach
        $psrFactory = new Psr17Factory();
        $psr = $psrFactory->createResponse(200);

        // Attach PSR response
        $web->setPsrResponse($psr);

        // Mutate AgaviWebResponse
        $web->setHttpStatusCode(201);
        $web->setHttpHeader('X-Test-Header', 'value');
        $web->setCookie('T', 'v', 0, '/', 'example.test', false, true, false);
        $web->setContent('hello');

    // sendContent should update the PSR body; capture output to avoid PHPUnit marking the test as risky
    ob_start();
    $web->sendContent();
    $out = ob_get_clean();

    $psr2 = $web->getPsrResponse();
        $this->assertNotNull($psr2, 'PSR response should still be attached');
        $this->assertEquals(201, $psr2->getStatusCode(), 'Status code should be forwarded');
        $this->assertTrue($psr2->hasHeader('X-Test-Header'));
    $this->assertStringContainsString('T=v', $psr2->getHeaderLine('Set-Cookie'));
    $this->assertEquals('hello', (string)$psr2->getBody());
    $this->assertEquals('hello', $out, 'sendContent should have echoed the content');
    }
}
