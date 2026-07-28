<?php

declare(strict_types=1);

namespace Quiote\Runtime\Request;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Quiote\Config\Config;
use Quiote\Request\WebRequest;
use Quiote\Runtime\Proxy\ForwardedAuthority;
use Quiote\Runtime\Proxy\ForwardedHeaderResolver;

/**
 * The single seam every worker runtime funnels its inbound request through, so
 * reverse-proxy correction happens identically whether the request came from
 * superglobals (a SAPI) or from a server that handed us a PSR-7 object
 * (RoadRunner, Swoole).
 *
 * Previously this lived in Kernel and applied the correction by writing to
 * $_SERVER before building the request, which is unavailable off-SAPI and
 * untestable anywhere. Here it is a pure transformation of a PSR-7 request.
 */
final class WorkerRequestFactory
{
    private ?ServerRequestCreator $creator = null;

    public function __construct(
        private readonly ForwardedHeaderResolver $resolver = new ForwardedHeaderResolver(),
        private readonly ?bool $trustForwardedHeaders = null,
    ) {
    }

    /**
     * Build the request from PHP's superglobals. Only valid under a runtime
     * whose capabilities report populatesSuperglobals.
     */
    public function fromGlobals(): WebRequest
    {
        // Memoized for the worker's lifetime: the creator is stateless, and
        // rebuilding four factories per request is pure waste in worker mode.
        if ($this->creator === null) {
            $psr17 = new Psr17Factory();
            $this->creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        }

        // Body parsing / JSON is handled later by middleware.
        return $this->fromPsr($this->creator->fromGlobals());
    }

    /**
     * Apply reverse-proxy correction and hand back a WebRequest the rest of
     * the framework can rely on.
     */
    public function fromPsr(ServerRequestInterface $request): WebRequest
    {
        $authority = $this->trustsForwardedHeaders()
            ? $this->resolver->resolve($request)
            : new ForwardedAuthority();

        if ($authority->isEmpty()) {
            return WebRequest::fromPsr($request);
        }

        return $this->applyAuthority($request, $authority);
    }

    private function trustsForwardedHeaders(): bool
    {
        return $this->trustForwardedHeaders ?? Config::getBool('core.proxy.trust_forwarded_headers', true);
    }

    /**
     * Rewrites the URI, the Host header and the CGI server params to match what
     * the proxy reported.
     *
     * The server params are the reason this can't be a chain of PSR-7 withers:
     * ServerRequestInterface has no withServerParams(), and plenty of Quiote
     * still reads REQUEST_SCHEME / HTTP_HOST / SERVER_PORT out of them (see
     * RequestUrl::fromServerParams()). So the corrected params go in through
     * WebRequest's constructor and the remaining state is copied across, the
     * same set WebRequest::fromPsr() copies.
     */
    private function applyAuthority(ServerRequestInterface $request, ForwardedAuthority $authority): WebRequest
    {
        $serverParams = $request->getServerParams();
        $scheme = $authority->scheme;
        $port = $authority->portExplicit ? $authority->port : null;

        if ($scheme !== null && $scheme !== '') {
            $serverParams['REQUEST_SCHEME'] = $scheme;
            if ($scheme === 'https') {
                $serverParams['HTTPS'] = 'on';
            }
        }

        if ($authority->host !== null && $authority->host !== '') {
            $authorityHost = ForwardedHeaderResolver::formatAuthorityHost($authority->host);
            if ($port !== null && ForwardedHeaderResolver::isPortNonDefault($scheme ?? 'http', $port)) {
                $authorityHost .= ':' . $port;
            }
            $serverParams['HTTP_HOST'] = $authorityHost;
            $serverParams['SERVER_NAME'] = $authority->host;
        }

        if ($port !== null) {
            $serverParams['SERVER_PORT'] = (string) $port;
        }

        $originalUri = $request->getUri();
        $correctedUri = $this->rewriteUri($originalUri, $authority);

        // Constructed with the *original* URI on purpose: the withUri() call
        // below is what syncs the Host header, and PSR-7 implementations
        // short-circuit withUri() when handed the URI instance the request
        // already holds -- which would silently skip that sync.
        $new = new WebRequest(
            $request->getMethod(),
            $originalUri,
            $request->getHeaders(),
            $request->getBody(),
            $request->getProtocolVersion(),
            $serverParams,
        );

        // preserveHost: false -- the Host header must follow the corrected
        // authority, not the one the proxy connected to us with.
        $new = $new->withUri($correctedUri, false);

        $new = $new
            ->withCookieParams($request->getCookieParams())
            ->withQueryParams($request->getQueryParams())
            ->withUploadedFiles($request->getUploadedFiles());

        $parsedBody = $request->getParsedBody();
        if ($parsedBody !== null) {
            $new = $new->withParsedBody($parsedBody);
        }

        foreach ($request->getAttributes() as $name => $value) {
            $new = $new->withAttribute((string) $name, $value);
        }

        return $new;
    }

    private function rewriteUri(UriInterface $uri, ForwardedAuthority $authority): UriInterface
    {
        if ($authority->scheme !== null && $authority->scheme !== '') {
            $uri = $uri->withScheme($authority->scheme);
        }
        $hostOverridden = $authority->host !== null && $authority->host !== '';
        if ($hostOverridden) {
            $uri = $uri->withHost($authority->host);
        }

        if ($authority->portExplicit && $authority->port !== null) {
            return $uri->withPort($authority->port);
        }

        if ($hostOverridden) {
            // The proxy named a different host but no port, so the port we were
            // *connected* on says nothing about the public authority and must
            // not be carried over -- otherwise generated URLs come out as
            // https://public.example:8080/.
            return $uri->withPort(null);
        }

        return $uri;
    }
}
