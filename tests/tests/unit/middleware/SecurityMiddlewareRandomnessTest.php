<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Support\Random\SeededRandomness;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A RequestHandlerInterface stub that records the `quiote.rid` attribute of
 * the request it was handed -- a concrete class, not an anonymous one, so its
 * $rid property is visible to PHPStan at the call site.
 */
final class SecurityMiddlewareRandomnessCapturingHandler implements RequestHandlerInterface
{
    public ?string $rid = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ridAttr = $request->getAttribute('quiote.rid');
        $this->rid = is_string($ridAttr) ? $ridAttr : null;

        return (new Psr17Factory())->createResponse(200);
    }
}

/**
 * The `quiote.rid` fallback SecurityMiddleware generates when no correlation
 * id is already attached to the request goes through the injected
 * RandomnessInterface seam (see §6.2 of the record/replay determinism plan)
 * rather than a direct random_bytes() call, so a replay engine can reproduce
 * it exactly.
 */
final class SecurityMiddlewareRandomnessTest extends UnitTestCase
{
    public function testGeneratesRidThroughTheInjectedRandomnessSeam(): void
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $middleware = new SecurityMiddleware($controller, randomness: new SeededRandomness(42));
        $handler = new SecurityMiddlewareRandomnessCapturingHandler();

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        $expected = bin2hex((new SeededRandomness(42))->bytes(4));
        $this->assertSame($expected, $handler->rid);
    }

    public function testSameSeedProducesTheSameRidAcrossTwoMiddlewareInstances(): void
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $handlerA = new SecurityMiddlewareRandomnessCapturingHandler();
        $handlerB = new SecurityMiddlewareRandomnessCapturingHandler();

        (new SecurityMiddleware($controller, randomness: new SeededRandomness(7)))
            ->process(new ServerRequest('GET', '/'), $handlerA);
        (new SecurityMiddleware($controller, randomness: new SeededRandomness(7)))
            ->process(new ServerRequest('GET', '/'), $handlerB);

        $this->assertSame($handlerA->rid, $handlerB->rid);
    }

    public function testPreservesAnExistingRidAttributeWithoutConsumingRandomness(): void
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $middleware = new SecurityMiddleware($controller, randomness: new SeededRandomness(1));
        $handler = new SecurityMiddlewareRandomnessCapturingHandler();

        $middleware->process(
            (new ServerRequest('GET', '/'))->withAttribute('quiote.rid', 'preset-rid'),
            $handler,
        );

        $this->assertSame('preset-rid', $handler->rid);
    }
}
