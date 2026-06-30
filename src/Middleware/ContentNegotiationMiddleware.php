<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;
use Negotiation\BaseAccept;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Http\MimeTypeRegistry;
use Negotiation\Negotiator;

/**
 * Minimal wrapper over middlewares/content-type.
 * Runs BEFORE routing so routing can overwrite the attribute.
 * If Accept absent, library falls back to its first default format; we still set that.
 * We disable nosniff header and save negotiated format name into 'output_type'.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'pre', priority: 50)]
class ContentNegotiationMiddleware implements MiddlewareInterface
{
    private ?string $defaultFormat = 'html';

    public function __construct(private AgaviController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $existing = $request->getAttribute('output_type');
        if ($existing !== null) {
            if (\Agavi\Util\DebugFlags::$response || \Agavi\Util\DebugFlags::$cookie) {
                AgaviDebugLogger::debug('[ContentNegotiationMiddleware] output_type already set to ' . $existing . ', skipping', $this->controller->getContext());
            }
            return $handler->handle($request);
        }

        $formats = $this->detectFormats($request);
        if (empty($formats) && $this->defaultFormat !== null) {
            $formats = [$this->defaultFormat];
        }

        $primary = $formats[0] ?? null;
        if ($primary !== null) {
            $request = $request->withAttribute('output_type', $primary)
                               ->withAttribute('output_formats', $formats);
        }

        if (\Agavi\Util\DebugFlags::$response || \Agavi\Util\DebugFlags::$cookie) {
            AgaviDebugLogger::debug('[ContentNegotiationMiddleware] set output_type=' . ($primary ?? 'null') . ' output_formats=' . implode(',', $formats) . ' uri=' . $request->getUri()->getPath() . ' accept=' . $request->getHeaderLine('Accept'), $this->controller->getContext());
        }

        return $handler->handle($request);
    }

    /** @return string[] Ordered list of format names, most-preferred first. */
    private function detectFormats(ServerRequestInterface $request): array
    {
        $format = $this->detectFromExtension($request);
        if ($format !== null) {
            return [$format];
        }
        return $this->detectFromHeader($request);
    }

    private function detectFromExtension(ServerRequestInterface $request): ?string
    {
        $path = $request->getUri()->getPath();
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }
        return MimeTypeRegistry::formatForExtension($extension);
    }

    /** @return string[] */
    private function detectFromHeader(ServerRequestInterface $request): array
    {
        AgaviDebugLogger::debug('[ContentNegotiationMiddleware] detecting content type from headers', $this->controller->getContext());
        if (!$request->hasHeader('Accept')) {
            return [];
        }
        $accept = $request->getHeaderLine('Accept');
        $mime = $this->negotiateHeader($accept, new Negotiator(), MimeTypeRegistry::allMimeTypes());
        AgaviDebugLogger::debug('[ContentNegotiationMiddleware] got ' . ($mime ?? 'null'), $this->controller->getContext());
        return $mime !== null ? MimeTypeRegistry::formatsForMime($mime) : [];
    }

    private function negotiateHeader(string $accept, Negotiator $negotiator, array $headers): ?string
    {
        $best = $negotiator->getBest($accept, $headers);
        return $best instanceof BaseAccept ? $best->getValue() : null;
    }
}
