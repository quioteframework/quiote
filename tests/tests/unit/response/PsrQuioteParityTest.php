<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Response\WebResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Http\SimpleStream;

class PsrQuioteParityTest extends UnitTestCase
{
    public function testStatusHeaderCookieAndBodyForwarding(): void
    {
        $web = new WebResponse();

        // Build a PSR response to attach
        $psrFactory = new Psr17Factory();
        $psr = $psrFactory->createResponse(200);

        // Attach PSR response
        $web->setPsrResponse($psr);

        // Mutate WebResponse
        $web->setHttpStatusCode(201);
        $web->setHttpHeader('X-Test-Header', 'value');
        $web->setCookie('T', 'v', 0, '/', 'example.test', false, true, false);
        $web->setContent('hello');

        // sendContent() now stages instead of echoing (transport belongs to the
        // runtime's emitter), but must still reflect the body onto the attached
        // PSR response.
        ob_start();
        $web->sendContent();
        $out = ob_get_clean();

        $psr2 = $web->getPsrResponse();
        $this->assertNotNull($psr2, 'PSR response should still be attached');
        $this->assertEquals(201, $psr2->getStatusCode(), 'Status code should be forwarded');
        $this->assertTrue($psr2->hasHeader('X-Test-Header'));
        $this->assertStringContainsString('T=v', $psr2->getHeaderLine('Set-Cookie'));
        $this->assertEquals('hello', (string) $psr2->getBody());
        $this->assertSame('', $out, 'nothing may be written to an output channel directly');
    }

    public function testStagedResponseCarriesTheSameStatusHeadersAndCookies(): void
    {
        $web = new WebResponse();
        $web->setHttpStatusCode(201);
        $web->setHttpHeader('X-Test-Header', 'value');
        $web->setCookie('T', 'v', 0, '/', 'example.test', false, true, false);
        $web->setContent('hello');

        $web->send();

        $staged = $web->getStagedResponse();
        $this->assertNotNull($staged);
        $this->assertSame(201, $staged->getStatusCode());
        $this->assertSame('value', $staged->getHeaderLine('X-Test-Header'));
        $this->assertStringContainsString('T=v', $staged->getHeaderLine('Set-Cookie'));
        $this->assertSame('hello', (string) $staged->getBody());
    }
}
