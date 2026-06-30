<?php

namespace Agavi\Middleware;

use Agavi\Controller\AgaviController;
use Agavi\Request\AgaviWebRequest;
use Agavi\Util\FormPopulationConfig;
use Agavi\Util\FormPopulationEngine;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies the modernized form population engine to PSR-7 responses so
 * container-less requests still receive automatic form value and error message population.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'after_action', after: 'AssetAggregationMiddleware', before: 'ExecutionTimeMiddleware')]
class FormPopulationMiddleware implements MiddlewareInterface
{
    private readonly FormPopulationEngine $engine;

    public function __construct(private readonly AgaviController $controller, ?FormPopulationEngine $engine = null)
    {
        $this->engine = $engine ?? new FormPopulationEngine();
        $this->engine->initialize($this->controller->getContext());
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $webRequest = $this->resolveWebRequest($request);
        $this->ensureDefaultConfig($webRequest);
        $request = $request->withAttribute('agavi.request_data', $webRequest);

        $response = $handler->handle($request);

        $content = $this->extractBody($response);
        if ($content === '') {
            return $response;
        }

        $globalResponse = $this->controller->getGlobalResponse();
        if (!is_object($globalResponse) || !method_exists($globalResponse, 'isContentMutable') || !$globalResponse->isContentMutable()) {
            return $response;
        }

        $globalResponse->setContent($content);

        $this->applyRuntimeConfig($webRequest, $request);

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

    private function resolveWebRequest(ServerRequestInterface $request): AgaviWebRequest
    {
        $rd = $request->getAttribute('agavi.request_data');
        if (!$rd instanceof AgaviWebRequest) {
            try {
                $rd = \Agavi\Agavi::context('web', true)?->getRequest();
            } catch (\Throwable) {
                $rd = null;
            }
        }
        if (!$rd instanceof AgaviWebRequest) {
            throw new \RuntimeException('Canonical AgaviWebRequest not initialized before FormPopulationMiddleware (unexpected).');
        }
        // No need to attachPsrRequest - AgaviWebRequest IS the PSR-7 request
        $query = $request->getQueryParams();
        if (is_array($query)) {
            foreach ($query as $k => $v) {
                $rd->setParameter($k, $v);
            }
        }
        $body = $request->getParsedBody();
        if (is_array($body)) {
            foreach ($body as $k => $v) {
                $rd->setParameter($k, $v);
            }
        }
        $routeParams = $request->getAttribute('route_params');
        if (is_array($routeParams)) {
            foreach ($routeParams as $k => $v) {
                $rd->setParameter($k, $v);
            }
        }
        return $rd;
    }

    private function ensureDefaultConfig(AgaviWebRequest $request): void
    {
        FormPopulationConfig::seed($request, $this->engine->getDefaults());
    }

    private function applyRuntimeConfig(AgaviWebRequest $webRequest, ServerRequestInterface $psrRequest): void
    {
        $config = FormPopulationConfig::get($webRequest);
        if (($config['force_request_uri'] ?? false) === false) {
            $path = $psrRequest->getUri()->getPath();
            if ($path === '') {
                $path = '/';
            }
            FormPopulationConfig::merge($webRequest, ['force_request_uri' => $path]);
        }
        if (($config['force_request_url'] ?? false) === false) {
            $url = (string) $psrRequest->getUri();
            if ($url === '') {
                $url = '/';
            }
            FormPopulationConfig::merge($webRequest, ['force_request_url' => $url]);
        }
    }

    private function extractBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        if (!is_object($body)) {
            return '';
        }
        try {
            if (method_exists($body, 'isSeekable') && $body->isSeekable()) {
                $body->rewind();
            }
            $contents = method_exists($body, 'getContents') ? $body->getContents() : (string) $body;
            if ($contents === '' && method_exists($body, 'isSeekable') && $body->isSeekable()) {
                $body->rewind();
                $contents = (string) $body;
            }
            return (string) $contents;
        } catch (\Throwable) {
            return '';
        }
    }
}
