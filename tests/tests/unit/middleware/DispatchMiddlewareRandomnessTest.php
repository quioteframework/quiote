<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Support\Random\SeededRandomness;
use Nyholm\Psr7\ServerRequest;

/**
 * DispatchMiddleware's `correlationId()` fallback (used when `quiote.rid` was
 * not already attached upstream) goes through the injected
 * RandomnessInterface seam rather than a direct random_bytes() call, per §6.2
 * of the record/replay determinism plan.
 */
final class DispatchMiddlewareRandomnessTest extends UnitTestCase
{
    private function correlationIdOf(DispatchMiddleware $middleware, \Psr\Http\Message\ServerRequestInterface $request): string
    {
        $method = new ReflectionMethod($middleware, 'correlationId');

        $result = $method->invoke($middleware, $request);
        self::assertIsString($result);

        return $result;
    }

    public function testGeneratesCorrelationIdThroughTheInjectedRandomnessSeam(): void
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $middleware = new DispatchMiddleware($controller, new SeededRandomness(42));

        $result = $this->correlationIdOf($middleware, new ServerRequest('GET', '/'));

        $this->assertSame(bin2hex((new SeededRandomness(42))->bytes(4)), $result);
    }

    public function testReusesAnExistingRidAttributeWithoutConsumingRandomness(): void
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $middleware = new DispatchMiddleware($controller, new SeededRandomness(1));

        $request = (new ServerRequest('GET', '/'))->withAttribute('quiote.rid', 'preset-rid');

        $this->assertSame('preset-rid', $this->correlationIdOf($middleware, $request));
    }
}
