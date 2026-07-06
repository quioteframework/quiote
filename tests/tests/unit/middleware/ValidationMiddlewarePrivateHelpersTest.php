<?php

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Execution\ActionDescriptor;
use Quiote\Middleware\ValidationMiddleware;

/**
 * Happy + failure path coverage for ValidationMiddleware's two 0%-covered
 * private helpers (resolveErrorOutputType, buildValidationProblemDetails),
 * exercised directly via reflection: driving them through process() would
 * require a fully-wired module/view/JSON-error pipeline whose setup cost
 * far outweighs the value for these two self-contained helpers.
 */
class ValidationMiddlewarePrivateHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('QUIOTE_TESTING')) {
            define('QUIOTE_TESTING', true);
        }
        \Quiote\Config\Config::set('core.use_translation', false);
    }

    /** @param array<mixed> $args */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        return $ref->invoke($object, ...$args);
    }

    /**
     * @param array<mixed> $args
     * @return array<array-key, mixed>
     */
    private function invokePrivateAndDecodeJson(object $object, string $method, array $args): array
    {
        $json = $this->invokePrivate($object, $method, $args);
        if (!is_string($json)) {
            throw new RuntimeException('Expected the helper to return a JSON string.');
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Expected valid JSON object output.');
        }
        return $decoded;
    }

    public function testResolveErrorOutputTypePrefersActionDescriptorOutputType(): void
    {
        $middleware = new ValidationMiddleware();
        $descriptor = new ActionDescriptor('Stub', 'Fail', 'read', 'JSON', false);
        $request = (new ServerRequest('GET', '/stub/fail'))
            ->withAttribute(ActionDescriptor::class, $descriptor);

        $result = $this->invokePrivate($middleware, 'resolveErrorOutputType', [$request, null]);

        $this->assertSame('json', $result);
    }

    public function testResolveErrorOutputTypeFallsBackToOutputTypeAttribute(): void
    {
        $middleware = new ValidationMiddleware();
        $request = (new ServerRequest('GET', '/stub/fail'))
            ->withAttribute('output_type', 'XML');

        $result = $this->invokePrivate($middleware, 'resolveErrorOutputType', [$request, null]);

        $this->assertSame('xml', $result);
    }

    public function testResolveErrorOutputTypeFallsBackToControllerWhenNoAttributes(): void
    {
        $middleware = new ValidationMiddleware();
        $request = new ServerRequest('GET', '/stub/fail');

        $controller = Context::getInstance('testing')->getController();
        $result = $this->invokePrivate($middleware, 'resolveErrorOutputType', [$request, $controller]);

        $this->assertIsString($result);
        $this->assertSame(strtolower($result), $result);
    }

    public function testResolveErrorOutputTypeDefaultsToHtmlWhenControllerThrows(): void
    {
        $middleware = new ValidationMiddleware();
        $request = new ServerRequest('GET', '/stub/fail');
        $brokenController = new class {
            public function getOutputType(): never
            {
                throw new RuntimeException('boom');
            }
        };

        $result = $this->invokePrivate($middleware, 'resolveErrorOutputType', [$request, $brokenController]);

        $this->assertSame('html', $result);
    }

    public function testBuildValidationProblemDetailsUsesFallbackMessagesWhenNoIncidents(): void
    {
        $middleware = new ValidationMiddleware();
        $request = new ServerRequest('GET', '/orders/offers/new');

        $decoded = $this->invokePrivateAndDecodeJson($middleware, 'buildValidationProblemDetails', [
            null,
            ['Something went wrong', 'Something went wrong', ''],
            $request,
        ]);

        $this->assertSame(400, $decoded['status']);
        $this->assertSame('/orders/offers/new', $decoded['instance']);
        $errors = $decoded['errors'];
        if (!is_array($errors)) {
            throw new RuntimeException('Expected "errors" to be an array.');
        }
        // Duplicate and empty fallback messages are deduplicated/filtered, and land under the "" (non-field) key.
        $this->assertSame(['Something went wrong'], $errors['']);
    }

    public function testBuildValidationProblemDetailsWithNoErrorsAtAll(): void
    {
        $middleware = new ValidationMiddleware();
        $request = new ServerRequest('GET', '/orders/offers/new');

        $decoded = $this->invokePrivateAndDecodeJson($middleware, 'buildValidationProblemDetails', [null, [], $request]);

        $this->assertSame(400, $decoded['status']);
        $this->assertArrayNotHasKey('errors', $decoded);
    }
}
