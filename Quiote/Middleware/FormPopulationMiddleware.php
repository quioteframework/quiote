<?php

namespace Quiote\Middleware;

use Quiote\Controller\Controller;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;
use Quiote\Util\FormPopulationConfig;
use Quiote\Util\FormPopulationEngine;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fills the forms in a rendered HTML response with the values the action or its view asked for,
 * such as an edit form prefilled from a stored record by {@see \Quiote\View\View::populateForm()}.
 *
 * Sits directly around {@see DispatchMiddleware}, inside {@see ValidationMiddleware}: a request
 * that failed validation is answered, and repopulated from the submission, before it gets here.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'action', after: 'SlotMiddleware', before: 'DispatchMiddleware')]
class FormPopulationMiddleware implements MiddlewareInterface
{
    private readonly FormPopulationEngine $engine;

    public function __construct(private readonly Controller $controller, ?FormPopulationEngine $engine = null)
    {
        $this->engine = $engine ?? new FormPopulationEngine();
        $this->engine->initialize($this->controller->getContext());
    }

    /**
     * Populates the forms in the response from the form population configuration on the current
     * request, as the action and its view left it.
     *
     * Population happens only when that configuration asks for it: a `populate` value (a map of
     * form id to values, a ParameterHolder for every form, or `true` for the request's own
     * parameters), or a `methods` list naming the request's method, in which case the forms are
     * filled from the request's parameters. With neither, the response is returned untouched.
     * Those parameters are the current request's, so strict validation has already pruned them.
     *
     * The response is also returned untouched unless the Content-Type looks like HTML (a missing
     * Content-Type is treated as HTML), the body contains a `<form`, and the controller's global
     * response still allows content mutation. When population changes the markup, a new body is
     * installed and any stale `Content-Length` header is removed. The engine is reset even if
     * population throws.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->isHtmlResponse($response)) {
            return $response;
        }

        $requestState = $this->controller->getContext()->getContainer()->get(RequestState::class);
        $webRequest = $requestState->current();
        if (!$this->populationRequested(FormPopulationConfig::get($webRequest))) {
            return $response;
        }

        $content = $this->extractBody($response);
        // Skip the DOM round-trip when there is no form to populate.
        if ($content === '' || stripos($content, '<form') === false) {
            return $response;
        }

        $globalResponse = $this->controller->getGlobalResponse();
        if (!$globalResponse->isContentMutable()) {
            return $response;
        }

        $webRequest = $this->applyRuntimeConfig($webRequest, $request);
        $requestState->publish($webRequest);

        $globalResponse->setContent($content);
        // The engine gates on the response's output type, so it must carry this request's rather
        // than whatever it last held, which under a long-running worker may be another request's.
        $outputType = $request->getAttribute('output_type');
        $globalResponse->setOutputType($this->controller->getOutputType(is_string($outputType) ? $outputType : null));

        try {
            $this->engine->populate($globalResponse, $webRequest);
        } finally {
            $this->engine->reset();
        }

        $updated = $globalResponse->getContent();
        if (!is_string($updated) || $updated === $content) {
            return $response;
        }

        $factory = \Quiote\Http\Psr17::factory();
        $response = $response->withBody($factory->createStream($updated));
        if ($response->hasHeader('Content-Length')) {
            $response = $response->withoutHeader('Content-Length');
        }

        return $response;
    }

    /**
     * Whether the configuration names something to populate: a `populate` source other than
     * `false`, or a non-empty `methods` list.
     *
     * @param array<string, mixed> $config
     */
    private function populationRequested(array $config): bool
    {
        $populate = $config['populate'] ?? null;
        if ($populate !== null && $populate !== false) {
            return true;
        }

        $methods = $config['methods'] ?? null;
        return is_array($methods) ? $methods !== [] : is_string($methods) && $methods !== '';
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

    /**
     * Cheap pre-body-read gate: skip the full body copy (extractBody() +
     * setContent()) for responses that plainly aren't HTML, so a large JSON or
     * binary response doesn't pay for two full-body string materializations
     * for nothing. Absent a Content-Type header we can't tell either way, so
     * fall through and let the engine's own gates decide.
     */
    private function isHtmlResponse(ResponseInterface $response): bool
    {
        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType === '') {
            return true;
        }
        return stripos($contentType, 'html') !== false;
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
        } catch (\Throwable $e) {
            // An unreadable body is served as it is, so its forms go out unpopulated.
            \Quiote\Logging\Log::for($this)->warning('could not read the response body, serving it without form population: ' . $e->getMessage());
            return '';
        }
    }
}
