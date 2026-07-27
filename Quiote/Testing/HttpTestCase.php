<?php

namespace Quiote\Testing;

use Nyholm\Psr7\ServerRequest;
use Quiote\Context;
use Quiote\Http\Psr17;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Testing\Http\TestResponse;

/**
 * Base class for fluent, full-pipeline HTTP tests: builds a real PSR-7
 * request and dispatches it through {@see Context::handle()} (the same
 * entry point production traffic uses), returning an assertable
 * {@see TestResponse}.
 */
abstract class HttpTestCase extends PhpUnitTestCase
{
    /** @var string the name of the context to use, null for the default context */
    protected ?string $contextName = null;

    private int $initialObLevel;

    protected function setUp(): void
    {
        parent::setUp();
        MiddlewareCatalog::initialize([]);
        $this->resetCachedPipeline();
        $this->initialObLevel = ob_get_level();
    }

    /**
     * Context::handle() lazily builds a MiddlewarePipeline once and caches it
     * for the Context singleton's lifetime (worker-mode optimization) -- a
     * MiddlewareCatalog change (e.g. replaceCoreStack()) made by one test
     * would otherwise have no effect on the next test in the same process,
     * since Context::getInstance() returns the same singleton across tests.
     * Same precedent as FragmentTestCase::clearSingletonModels(): reflection
     * into Context's protected state, for test isolation only.
     */
    private function resetCachedPipeline(): void
    {
        $context = $this->getContext();
        $property = new \ReflectionProperty($context, 'psrKernel');
        $property->setValue($context, null);
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    protected function getContext(): Context
    {
        return Context::getInstance($this->contextName);
    }

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->dispatch('GET', $uri, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->dispatchForm('POST', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->dispatchForm('PUT', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->dispatchForm('PATCH', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->dispatchForm('DELETE', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $factory = Psr17::factory();
        $body = $factory->createStream((string)json_encode($data));
        $headers['Content-Type'] ??= 'application/json';
        return $this->dispatchWithBody($method, $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function dispatchForm(string $method, string $uri, array $data, array $headers): TestResponse
    {
        $factory = Psr17::factory();
        $body = $factory->createStream(http_build_query($data));
        $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
        return $this->dispatchWithBody($method, $uri, $body, $headers);
    }

    /** @param array<string, string> $headers */
    private function dispatch(string $method, string $uri, array $headers): TestResponse
    {
        $request = new ServerRequest($method, $uri, $headers);
        return new TestResponse($this->getContext()->handle($request));
    }

    /** @param array<string, string> $headers */
    private function dispatchWithBody(string $method, string $uri, \Psr\Http\Message\StreamInterface $body, array $headers): TestResponse
    {
        $request = (new ServerRequest($method, $uri, $headers))->withBody($body);
        return new TestResponse($this->getContext()->handle($request));
    }
}
