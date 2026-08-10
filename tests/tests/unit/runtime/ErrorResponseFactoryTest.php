<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Config\Config;
use Quiote\Exception\Rendering\ExceptionRenderer;
use Quiote\Exception\Rendering\ExceptionRendererRegistry;
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

    /**
     * The renderer is a registered seam, so an application's own developer
     * renderer can throw. This is the backstop for a request that already
     * escaped the pipeline -- if it threw again here the client would get
     * nothing at all, which is exactly what returning a response prevents.
     */
    public function testARendererThatThrowsFallsBackToAPlainInternalServerError(): void
    {
        Config::set('core.developer_exceptions', true);
        ExceptionRendererRegistry::setDeveloperRenderer(static fn(): ExceptionRenderer => new ThrowingExceptionRenderer());

        try {
            $response = (new ErrorResponseFactory())->fromThrowable(new RuntimeException('exploded'));

            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
            $this->assertSame('Internal Server Error', (string) $response->getBody());
        } finally {
            ExceptionRendererRegistry::reset();
            Config::remove('core.developer_exceptions');
        }
    }

    /**
     * The last-resort body is deliberately free of the exception's own detail:
     * the renderer that would have decided what is safe to disclose is the
     * thing that just failed.
     */
    public function testTheLastResortResponseLeaksNothingAboutEitherFailure(): void
    {
        Config::set('core.developer_exceptions', true);
        ExceptionRendererRegistry::setDeveloperRenderer(static fn(): ExceptionRenderer => new ThrowingExceptionRenderer());

        try {
            $response = (new ErrorResponseFactory())->fromThrowable(new RuntimeException('database password is hunter2'));
            $body = (string) $response->getBody();

            $this->assertStringNotContainsString('hunter2', $body);
            $this->assertStringNotContainsString('renderer exploded', $body);
        } finally {
            ExceptionRendererRegistry::reset();
            Config::remove('core.developer_exceptions');
        }
    }
}

/** An application renderer that fails, to reach the factory's last-resort path. */
final class ThrowingExceptionRenderer implements ExceptionRenderer
{
    public function render(Throwable $e, ServerRequestInterface $request, int $status, ?string $correlationId): ResponseInterface
    {
        throw new RuntimeException('renderer exploded');
    }
}
