<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Controller\AgaviController;

/**
 * Performs simple content negotiation to select an Agavi output type.
 * Order of precedence (first value that yields a known output type wins):
 *  1. Explicit request attribute 'output_type' (already set by routing or previous middleware)
 *  2. Query parameter "_format" / "format"
 *  3. Extension fragment in path (e.g. /foo/bar.json)
 *  4. Accept header best match (basic quality weight parsing)
 *  5. Controller default output type (left unchanged)
 *
 * Sets a normalized lowercase 'output_type' request attribute for downstream middleware.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'routing', priority: 10)]
class ContentNegotiationMiddleware implements MiddlewareInterface
{
    private array $mimeMap; // mime => outputType
    private array $extensionMap; // ext => outputType

    public function __construct(private AgaviController $controller, array $mimeMap = [], array $extensionMap = [])
    {
        // Provide sensible defaults if caller omitted maps.
        $this->mimeMap = $mimeMap ?: [
            'text/html' => 'html',
            'application/xhtml+xml' => 'html',
            'application/json' => 'json',
            'application/ld+json' => 'json',
            'application/xml' => 'xml',
            'text/xml' => 'xml',
        ];
        $this->extensionMap = $extensionMap ?: [
            'html' => 'html', 'htm' => 'html',
            'json' => 'json',
            'xml' => 'xml',
        ];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // If routing already chose one, respect it.
        $chosen = $request->getAttribute('output_type');
        if(is_string($chosen) && $chosen !== '') {
            return $handler->handle($request);
        }

        // 1. Explicit query parameter (&_format= or &format=)
        $query = $request->getQueryParams();
        foreach(['_format','format'] as $key) {
            if(isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $candidate = strtolower($query[$key]);
                if($this->isValidOutputType($candidate)) {
                    return $handler->handle($request->withAttribute('output_type', $candidate));
                }
            }
        }

        // 2. Path extension (only if no query param matched)
        $path = $request->getUri()->getPath();
        $last = basename($path);
        if(str_contains($last, '.')) {
            $ext = strtolower(substr($last, strrpos($last, '.') + 1));
            if(isset($this->extensionMap[$ext])) {
                $candidate = $this->extensionMap[$ext];
                if($this->isValidOutputType($candidate)) {
                    return $handler->handle($request->withAttribute('output_type', $candidate));
                }
            }
        }

        // 3. Accept header negotiation
        $accept = $request->getHeaderLine('Accept');
        if($accept) {
            $best = $this->negotiateAccept($accept);
            if($best && $this->isValidOutputType($best)) {
                return $handler->handle($request->withAttribute('output_type', $best));
            }
        }

        // 4. Fallback: leave controller default (nothing to add)
        return $handler->handle($request);
    }

    private function isValidOutputType(string $name): bool
    {
        try { $this->controller->getOutputType($name); return true; } catch(\Throwable) { return false; }
    }

    /**
     * Very small Accept: parser returning best matching configured output type name.
     * Handles quality factors (q=). Returns null if no match.
     */
    private function negotiateAccept(string $header): ?string
    {
        $candidates = [];
        foreach(explode(',', $header) as $part) {
            $part = trim($part);
            if($part === '') { continue; }
            $segments = explode(';', $part);
            $mime = strtolower(trim(array_shift($segments)));
            $q = 1.0;
            foreach($segments as $seg) {
                $seg = trim($seg);
                if(str_starts_with($seg, 'q=')) {
                    $val = substr($seg,2);
                    if(is_numeric($val)) { $q = (float)$val; }
                }
            }
            if(isset($this->mimeMap[$mime])) {
                $ot = $this->mimeMap[$mime];
                $candidates[$ot] = max($candidates[$ot] ?? 0, $q);
            } elseif($mime === '*/*') {
                // wildcard applies to first registered output types: approximate by granting low score to all
                foreach($this->mimeMap as $m => $mappedOt) { $candidates[$mappedOt] = max($candidates[$mappedOt] ?? 0, $q * 0.5); }
            }
        }
        if(!$candidates) { return null; }
        arsort($candidates, SORT_NUMERIC);
        return array_key_first($candidates);
    }
}
