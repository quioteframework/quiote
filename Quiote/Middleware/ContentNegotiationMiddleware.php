<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Negotiation\BaseAccept;
use Quiote\Http\MimeTypeRegistry;
use Negotiation\Negotiator;

/**
 * Minimal wrapper over middlewares/content-type.
 * Runs BEFORE routing so routing can overwrite the attribute.
 * If Accept absent, library falls back to its first default format; we still set that.
 * We disable nosniff header and save negotiated format name into 'output_type'.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'pre', priority: 50)]
class ContentNegotiationMiddleware implements MiddlewareInterface
{
    private string $defaultFormat = 'html';

    /** Stateless negotiator; built once per worker instead of per request. */
    private readonly Negotiator $negotiator;

    public function __construct()
    {
        $this->negotiator = new Negotiator();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $existing = $request->getAttribute('output_type');
        if ($existing !== null) {
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                \Quiote\Logging\Log::for($this)->debug('[ContentNegotiationMiddleware] output_type already set to ' . $existing . ', skipping');
            }
            return $handler->handle($request);
        }

        $formats = $this->detectFormats($request);
        if (empty($formats)) {
            $formats = [$this->defaultFormat];
        }

        $primary = $formats[0] ?? null;
        if ($primary !== null) {
            $request = $request->withAttribute('output_type', $primary)
                               ->withAttribute('output_formats', $formats);
        }

        if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
            \Quiote\Logging\Log::for($this)->debug('[ContentNegotiationMiddleware] set output_type=' . ($primary ?? 'null') . ' output_formats=' . implode(',', $formats) . ' uri=' . $request->getUri()->getPath() . ' accept=' . $request->getHeaderLine('Accept'));
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
        $dbg = \Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug);
        if ($dbg) { \Quiote\Logging\Log::for($this)->debug('[ContentNegotiationMiddleware] detecting content type from headers'); }
        if (!$request->hasHeader('Accept')) {
            return [];
        }
        $accept = $request->getHeaderLine('Accept');
        // Fast path: browsers overwhelmingly lead with "text/html" or "*/*",
        // both of which resolve to html (html is the first negotiable type, so
        // it wins wildcard/tie requests anyway). Skip the negotiator entirely
        // for that dominant case.
        $firstType = strtolower(trim(strtok($accept, ',;') ?: ''));
        if ($firstType === '' || $firstType === 'text/html' || $firstType === '*/*') {
            return ['html'];
        }
        $mime = $this->negotiateHeader($accept, $this->negotiator, MimeTypeRegistry::negotiableMimeTypes());
        if ($dbg) { \Quiote\Logging\Log::for($this)->debug('[ContentNegotiationMiddleware] got ' . ($mime ?? 'null')); }
        return $mime !== null ? MimeTypeRegistry::formatsForMime($mime) : [];
    }

    /**
     * @param string[] $headers
     */
    private function negotiateHeader(string $accept, Negotiator $negotiator, array $headers): ?string
    {
        $best = $negotiator->getBest($accept, $headers);
        return $best instanceof BaseAccept ? $best->getValue() : null;
    }
}
