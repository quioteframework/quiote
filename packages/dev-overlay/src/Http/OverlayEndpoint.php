<?php

declare(strict_types=1);

namespace Quiote\DevOverlay\Http;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Quiote\DevOverlay\DevOverlayConfig;
use Quiote\DevOverlay\Report\ExceptionReportStoreInterface;
use Quiote\Http\ProblemDetails;
use RuntimeException;

/**
 * Serves the overlay's own two URLs under `dev_overlay.path`: the script itself
 * (`<path>/overlay.js`) and one stored report as JSON (`<path>/<id>`).
 *
 * Neither is an application route. Both are answered in the bootstrap phase, before session,
 * security or routing middleware run, so loading the overlay never touches application state.
 */
final readonly class OverlayEndpoint
{
    public const string SCRIPT_NAME = 'overlay.js';

    public function __construct(
        private ExceptionReportStoreInterface $store,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private string $scriptFile = __DIR__ . '/../../resources/overlay.js',
    ) {
    }

    /** The response for a request under the overlay path, or null for any other request. */
    public function serve(ServerRequestInterface $request, DevOverlayConfig $config): ?ResponseInterface
    {
        $prefix = $config->path . '/';
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->problem(405, 'The developer overlay only answers GET.')->withHeader('Allow', 'GET, HEAD');
        }

        $name = substr($path, strlen($prefix));

        return $name === self::SCRIPT_NAME ? $this->script() : $this->report($name);
    }

    private function script(): ResponseInterface
    {
        $source = @file_get_contents($this->scriptFile);
        if ($source === false) {
            throw new RuntimeException(sprintf('The developer overlay script %s could not be read.', $this->scriptFile));
        }

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($source));
    }

    private function report(string $id): ResponseInterface
    {
        $report = $this->store->get($id);
        if ($report === null) {
            return $this->problem(404, 'No exception report is stored under this id; it may have been pruned.');
        }

        try {
            $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Exception report %s could not be encoded: %s', $id, $e->getMessage()), 0, $e);
        }

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($json));
    }

    private function problem(int $status, string $detail): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', ProblemDetails::MEDIA_TYPE)
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream(ProblemDetails::create(status: $status, detail: $detail)->toJson()));
    }
}
