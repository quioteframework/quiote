<?php

declare(strict_types=1);

namespace Quiote\DevOverlay;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\DevOverlay\Http\HtmlScriptInjector;
use Quiote\DevOverlay\Http\OverlayEndpoint;
use Quiote\DevOverlay\Report\ExceptionReportBuilder;
use Quiote\DevOverlay\Report\ExceptionReportStoreInterface;
use Quiote\Error\HandledException;
use Quiote\Error\HandledExceptions;
use Quiote\Logging\Log;
use Quiote\Request\RequestState;
use Quiote\Support\Random\RandomnessInterface;
use Throwable;

/**
 * Connects the browser overlay to the exceptions this app catches.
 *
 * A request is reported when it ended in an exception -- the one `ErrorHandlingMiddleware` caught
 * and published on {@see RequestState} as the `Throwable` attribute -- or when the application
 * reported exceptions it handled itself through {@see \Quiote\Error\ErrorReporting::handled()}.
 * Its exceptions are stored as one report and the response gains an {@see HEADER} naming it --
 * HTML page, JSON API response or anything else alike, since that header is how the overlay script
 * recognises a `fetch()` or XHR worth showing. A full HTML document additionally gets the overlay
 * script injected, along with the id of its own report if it has one.
 *
 * Registered `phase: 'bootstrap', priority: 1050`: outside `ErrorHandlingMiddleware` (1000), so it
 * sees the response that middleware rendered and the exception it published, and inside
 * `RecorderMiddleware` (1100), so a recorded cassette carries the response exactly as the browser
 * received it.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 1050)]
final class DevOverlayMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Quiote-Exception';

    public function __construct(
        private readonly Context $context,
        private readonly ExceptionReportStoreInterface $store,
        private readonly ExceptionReportBuilder $builder,
        private readonly OverlayEndpoint $endpoint,
        private readonly HtmlScriptInjector $injector,
        private readonly StreamFactoryInterface $streams,
        private readonly RandomnessInterface $randomness,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = DevOverlayConfig::fromConfig();
        if (!$config->active) {
            return $handler->handle($request);
        }

        $served = $this->endpoint->serve($request, $config);
        if ($served !== null) {
            return $served;
        }

        $response = $handler->handle($request);

        $reportId = $this->reportExceptions($request, $response);
        if ($reportId !== null) {
            $response = $response->withHeader(self::HEADER, $reportId);
        }

        return $this->withOverlayScript($request, $response, $config, $reportId);
    }

    /**
     * Stores the exception this request ended in and the ones it handled, if there are any, and
     * answers the id they were stored under.
     */
    private function reportExceptions(ServerRequestInterface $request, ResponseInterface $response): ?string
    {
        $caught = $this->caughtException();
        $handled = $this->handledExceptions($caught);
        if ($caught === null && $handled === []) {
            return null;
        }

        $id = bin2hex($this->randomness->bytes(16));
        try {
            $this->store->put($id, $this->builder->build($id, $caught, $request, $response->getStatusCode(), $handled));
        } catch (Throwable $e) {
            Log::for($this)->warning(sprintf(
                'The %s on %s %s could not be stored for the developer overlay: %s',
                $caught === null ? 'handled exceptions' : $caught::class . ' caught',
                $request->getMethod(),
                $request->getUri()->getPath(),
                $e->getMessage(),
            ));

            return null;
        }

        return $id;
    }

    /**
     * The exception `ErrorHandlingMiddleware` published for this request. `tryGet()`: a context
     * without a {@see RequestState} binding has nothing published, which is not an error.
     */
    private function caughtException(): ?Throwable
    {
        $requestState = $this->context->getContainer()->tryGet(RequestState::class);
        if (!$requestState instanceof RequestState) {
            return null;
        }
        $exception = $requestState->current()->getAttribute(Throwable::class);

        return $exception instanceof Throwable ? $exception : null;
    }

    /**
     * The exceptions the application reported as handled, less the one the request ended in: an
     * action that reports an exception and then rethrows it would otherwise show it twice.
     * `tryGet()`: a core without {@see HandledExceptions} has none to report.
     *
     * @return list<HandledException>
     */
    private function handledExceptions(?Throwable $caught): array
    {
        $handled = $this->context->getContainer()->tryGet(HandledExceptions::class);
        if (!$handled instanceof HandledExceptions) {
            return [];
        }

        return array_values(array_filter(
            $handled->all(),
            static fn(HandledException $entry): bool => $entry->exception !== $caught,
        ));
    }

    private function withOverlayScript(ServerRequestInterface $request, ResponseInterface $response, DevOverlayConfig $config, ?string $reportId): ResponseInterface
    {
        if (!self::isInjectable($request, $response)) {
            return $response;
        }

        $attributes = ['endpoint' => $config->path];
        if ($reportId !== null) {
            $attributes['exception'] = $reportId;
        }
        if ($config->editorUrl !== '') {
            $attributes['editor'] = $config->editorUrl;
        }

        $html = $this->injector->inject((string) $response->getBody(), $config->path . '/' . OverlayEndpoint::SCRIPT_NAME, $attributes);
        if ($html === null) {
            return $response;
        }

        $response = $response->withBody($this->streams->createStream($html));

        return $response->hasHeader('Content-Length')
            ? $response->withHeader('Content-Length', (string) strlen($html))
            : $response;
    }

    /**
     * An HTML document the browser will render as-is: not a HEAD or bodiless status, declared
     * HTML or XHTML (an undeclared type is never assumed to be HTML), and not already encoded --
     * splicing text into a gzip body would corrupt it.
     */
    private static function isInjectable(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        if ($request->getMethod() === 'HEAD' || in_array($response->getStatusCode(), [204, 304], true)) {
            return false;
        }
        $encoding = strtolower($response->getHeaderLine('Content-Encoding'));
        if ($encoding !== '' && $encoding !== 'identity') {
            return false;
        }
        $contentType = $response->getHeaderLine('Content-Type');

        return stripos($contentType, 'text/html') !== false || stripos($contentType, 'application/xhtml+xml') !== false;
    }
}
