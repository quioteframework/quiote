<?php

namespace Agavi\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Agavi\Request\AgaviWebRequest; // web-specific
use Agavi\Request\AgaviRequest; // base
use InvalidArgumentException;

/**
 * Phase 1 thin adapter exposing a legacy Agavi request as PSR-7 ServerRequestInterface.
 * Mutation methods clone shallow data arrays (headers, attributes, query/body params) and
 * keep a reference to the underlying legacy request so unresolved code paths still work.
 *
 * NOTE: This is intentionally incomplete; only methods needed by early middleware implemented.
 */
class PsrServerRequestAdapter implements ServerRequestInterface
{
    private AgaviRequest $legacy;
    private array $attributes = [];
    private array $queryParams = [];
    private array $parsedBody = [];
    private array $serverParams = [];
    private array $cookieParams = [];
    private array $uploadedFiles = [];
    private array $headers = [];
    private string $protocolVersion = '1.1';
    private ?StreamInterface $body = null;
    private UriInterface $uri;
    private string $method;

    public function __construct(AgaviRequest $legacy, UriInterface $uri, string $method, StreamInterface $body, array $server, array $headers, array $cookies, array $query, array $parsedBody, array $uploadedFiles)
    {
        $this->legacy = $legacy;
        $this->uri = $uri;
        $this->method = strtoupper($method);
        $this->body = $body;
        $this->serverParams = $server;
        $this->headers = $this->normalizeHeaders($headers);
        $this->cookieParams = $cookies;
        $this->queryParams = $query;
        $this->parsedBody = $parsedBody;
        $this->uploadedFiles = $uploadedFiles;
    }

    public function getLegacyRequest(): AgaviRequest
    {
        return $this->legacy;
    }

    private function cloneSelf(): self
    {
        return clone $this;
    }

    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[strtolower($k)] = is_array($v) ? array_values($v) : [$v];
        }
        return $out;
    }

    // ----- ServerRequestInterface specifics -----
    public function getServerParams(): array
    {
        return $this->serverParams;
    }
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }
    public function withCookieParams(array $cookies): static
    {
        $c = $this->cloneSelf();
        $c->cookieParams = $cookies;
        return $c;
    }
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }
    public function withQueryParams(array $query): static
    {
        $c = $this->cloneSelf();
        $c->queryParams = $query;
        return $c;
    }
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }
    public function withUploadedFiles(array $uploadedFiles): static
    {
        $c = $this->cloneSelf();
        $c->uploadedFiles = $uploadedFiles;
        return $c;
    }
    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }
    public function withParsedBody($data): static
    {
        if (!is_array($data) && $data !== null) throw new InvalidArgumentException('parsed body must be array|null');
        $c = $this->cloneSelf();
        $c->parsedBody = $data ?? [];
        return $c;
    }
    public function getAttributes(): array
    {
        return $this->attributes;
    }
    public function getAttribute($name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }
    public function withAttribute($name, $value): static
    {
        $c = $this->cloneSelf();
        $c->attributes[$name] = $value;
        return $c;
    }
    public function withoutAttribute($name): static
    {
        $c = $this->cloneSelf();
        unset($c->attributes[$name]);
        return $c;
    }

    // ----- MessageInterface -----
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }
    public function withProtocolVersion($version): static
    {
        $c = $this->cloneSelf();
        $c->protocolVersion = $version;
        return $c;
    }
    public function getHeaders(): array
    {
        return $this->headers;
    }
    public function hasHeader($name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }
    public function getHeader($name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }
    public function withHeader($name, $value): static
    {
        $c = $this->cloneSelf();
        $c->headers[strtolower($name)] = is_array($value) ? array_values($value) : [$value];
        return $c;
    }
    public function withAddedHeader($name, $value): static
    {
        $c = $this->cloneSelf();
        $ln = strtolower($name);
        $add = is_array($value) ? $value : [$value];
        $c->headers[$ln] = array_merge($c->headers[$ln] ?? [], $add);
        return $c;
    }
    public function withoutHeader($name): static
    {
        $c = $this->cloneSelf();
        unset($c->headers[strtolower($name)]);
        return $c;
    }
    public function getBody(): StreamInterface
    {
        return $this->body;
    }
    public function withBody(StreamInterface $body): static
    {
        $c = $this->cloneSelf();
        $c->body = $body;
        return $c;
    }

    // ----- RequestInterface -----
    public function getRequestTarget(): string
    {
        return $this->uri->getPath() ?: '/';
    }
    public function withRequestTarget($requestTarget): static
    {
        $c = $this->cloneSelf(); /* ignore mutability of target */
        return $c;
    }
    public function getMethod(): string
    {
        return $this->method;
    }
    public function withMethod($method): static
    {
        $c = $this->cloneSelf();
        $c->method = strtoupper($method);
        return $c;
    }
    public function getUri(): UriInterface
    {
        return $this->uri;
    }
    public function withUri(UriInterface $uri, $preserveHost = false): static
    {
        $c = $this->cloneSelf();
        $c->uri = $uri;
        if (!$preserveHost && $uri->getHost()) $c->headers['host'] = [$uri->getHost()];
        return $c;
    }

    // convenience for template migration: emulate legacy attribute holder semantics for css/js lists
    public function appendListAttribute(string $name, string $value): void
    {
        $list = $this->attributes[$name] ?? [];
        if (!is_array($list)) {
            $list = [$list];
        }
        $list[] = $value;
        $this->attributes[$name] = $list;
    }
}
