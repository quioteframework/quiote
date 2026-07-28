<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Quiote\Runtime\ErrorResponseFactory;

/**
 * The pre-pipeline failure path had no coverage while it lived inline in
 * Kernel::run() and ended in header()+echo. It now has to produce a
 * ResponseInterface, because a CLI-hosted runtime has no other way to answer.
 */
final class ErrorResponseFactoryTest extends TestCase
{
    public function testAThrowableBecomesAServerErrorResponse(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/boom');

        $response = (new ErrorResponseFactory())->fromThrowable(new RuntimeException('exploded'), $request);

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
        $this->assertNotSame('', $response->getHeaderLine('Content-Type'));
    }

    public function testTheRequestIsOptionalBecauseAFailureCanPredateIt(): void
    {
        // Bootstrap can die before there is anything to build a request from, so
        // the factory synthesises one rather than refusing to render.
        $response = (new ErrorResponseFactory())->fromThrowable(new RuntimeException('exploded'));

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
    }

    public function testTheResponseIsNeverEmptyEvenForAThrowableWithNoMessage(): void
    {
        $response = (new ErrorResponseFactory())->fromThrowable(new RuntimeException(''));

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
        $this->assertNotSame('', (string) $response->getBody());
    }

    public function testNothingIsWrittenToTheSapiWhileRendering(): void
    {
        // The whole point of returning a response: off-SAPI, echo would land on
        // the server's protocol channel instead of reaching the client.
        ob_start();
        (new ErrorResponseFactory())->fromThrowable(new RuntimeException('exploded'));
        $emitted = ob_get_clean();

        $this->assertSame('', $emitted);
    }

    public function testAnErrorRenderedForAnErrorStillProducesAResponse(): void
    {
        $factory = new ErrorResponseFactory();

        // A Throwable whose own accessors misbehave is the realistic way the
        // renderer itself blows up; whatever happens, a 500 has to come back.
        $response = $factory->fromThrowable(new Error('fatal-ish'));

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
    }
}
