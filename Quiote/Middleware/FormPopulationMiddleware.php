<?php

namespace Quiote\Middleware;

use Quiote\Controller\Controller;
use Quiote\Request\WebRequest;
use Quiote\Util\FormPopulationConfig;
use Quiote\Util\FormPopulationEngine;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies the modernized form population engine to PSR-7 responses so
 * container-less requests still receive automatic form value and error message population.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'after_action', after: 'AssetAggregationMiddleware', before: 'ExecutionTimeMiddleware')]
class FormPopulationMiddleware implements MiddlewareInterface
{
    private readonly FormPopulationEngine $engine;

    public function __construct(private readonly Controller $controller, ?FormPopulationEngine $engine = null)
    {
        $this->engine = $engine ?? new FormPopulationEngine();
        $this->engine->initialize($this->controller->getContext());
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $webRequest = $this->resolveWebRequest($request);
        $webRequest = $this->ensureDefaultConfig($webRequest);
        $this->controller->getContext()->setRequest($webRequest);
        $request = $request->withAttribute('quiote.request_data', $webRequest);

        $response = $handler->handle($request);

        $content = $this->extractBody($response);
        if ($content === '') {
            return $response;
        }

        $globalResponse = $this->controller->getGlobalResponse();
        if (!$globalResponse->isContentMutable()) {
            return $response;
        }

        $globalResponse->setContent($content);

        $webRequest = $this->applyRuntimeConfig($webRequest, $request);

        try {
            $this->engine->populate($globalResponse, $webRequest);
        } finally {
            $this->engine->reset();
        }

        $updated = $globalResponse->getContent();
        if (!is_string($updated) || $updated === $content) {
            return $response;
        }

        $factory = new Psr17Factory();
        $response = $response->withBody($factory->createStream($updated));
        if ($response->hasHeader('Content-Length')) {
            $response = $response->withoutHeader('Content-Length');
        }

        return $response;
    }

    private function resolveWebRequest(ServerRequestInterface $request): WebRequest
    {
        $rd = $request->getAttribute('quiote.request_data');
        if (!$rd instanceof WebRequest) {
            try {
                $rd = $this->controller->getContext()->getRequest();
            } catch (\Throwable) {
                $rd = null;
            }
        }
        if (!$rd instanceof WebRequest) {
            throw new \RuntimeException('Canonical WebRequest not initialized before FormPopulationMiddleware (unexpected).');
        }
        // No need to attachPsrRequest - WebRequest IS the PSR-7 request
        $query = $request->getQueryParams();
        foreach ($query as $k => $v) {
            $rd = $rd->setParameter($k, $v);
        }
        $body = $request->getParsedBody();
        if (is_array($body)) {
            foreach ($body as $k => $v) {
                $rd = $rd->setParameter($k, $v);
            }
        }
        $routeParams = $request->getAttribute('route_params');
        if (is_array($routeParams)) {
            foreach ($routeParams as $k => $v) {
                $rd = $rd->setParameter($k, $v);
            }
        }
        return $rd;
    }

    private function ensureDefaultConfig(WebRequest $request): WebRequest
    {
        $updated = FormPopulationConfig::seed($request, $this->engine->getDefaults());
        return $updated instanceof WebRequest ? $updated : $request;
    }

    private function applyRuntimeConfig(WebRequest $webRequest, ServerRequestInterface $psrRequest): WebRequest
    {
        $config = FormPopulationConfig::get($webRequest);
        if (($config['force_request_uri'] ?? false) === false) {
            $path = $psrRequest->getUri()->getPath();
            if ($path === '') {
                $path = '/';
            }
            $updated = FormPopulationConfig::merge($webRequest, ['force_request_uri' => $path]);
            $webRequest = $updated instanceof WebRequest ? $updated : $webRequest;
        }
        if (($config['force_request_url'] ?? false) === false) {
            $url = (string) $psrRequest->getUri();
            if ($url === '') {
                $url = '/';
            }
            $updated = FormPopulationConfig::merge($webRequest, ['force_request_url' => $url]);
            $webRequest = $updated instanceof WebRequest ? $updated : $webRequest;
        }
        return $webRequest;
    }

    private function extractBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $contents = $body->getContents();
            if ($contents === '' && $body->isSeekable()) {
                $body->rewind();
                $contents = (string) $body;
            }
            return (string) $contents;
        } catch (\Throwable) {
            return '';
        }
    }
}
