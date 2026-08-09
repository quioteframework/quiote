<?php

declare(strict_types=1);

namespace Quiote\Request;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * Pure one-line delegations to the wrapped Nyholm\Psr7\ServerRequest.
 * Everything here is mechanical passthrough with no Quiote-specific
 * behavior; methods that need to react to the change (e.g. withUri()
 * re-syncing URL metadata) stay on WebRequest itself.
 */
trait Psr7DelegationTrait
{
    private \Nyholm\Psr7\ServerRequest $psrRequest;

    /**
     * Clone this WebRequest with the wrapped PSR-7 request instance replaced.
     */
    private function withPsrRequest(\Nyholm\Psr7\ServerRequest $psrRequest): static
    {
        $new = clone $this;
        $new->psrRequest = $psrRequest;
        $new->parametersCache = null;
        return $new;
    }

    /**
     * Bulk counterpart to withoutHeader(): removes many headers with a single
     * PSR-7 message clone instead of one clone per header (each
     * withoutHeader() call clones the whole wrapped request). Reaches into
     * Nyholm\Psr7\ServerRequest's private header maps via a bound closure --
     * the same end state withoutHeader() produces, just batched.
     * @param array<int, string> $names
     */
    private function withoutHeaders(array $names): static
    {
        if ($names === []) {
            return $this;
        }
        $newPsr = clone $this->psrRequest;
        $mutate = \Closure::bind(function (array $names): void {
            foreach ($names as $header) {
                $normalized = \strtr($header, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
                if (isset($this->headerNames[$normalized])) {
                    $orig = $this->headerNames[$normalized];
                    unset($this->headers[$orig], $this->headerNames[$normalized]);
                }
            }
        }, $newPsr, \Nyholm\Psr7\ServerRequest::class);
        $mutate($names);
        return $this->withPsrRequest($newPsr);
    }

    /** Returns the HTTP protocol version of the wrapped PSR-7 request, e.g. `1.1`. */
    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->psrRequest->getProtocolVersion();
    }

    /**
     * Returns a clone carrying the given HTTP protocol version.
     *
     * This request is left untouched; the clone wraps a new PSR-7 request and
     * starts with an empty parameter cache.
     */
    #[\Override]
    public function withProtocolVersion($version): static
    {
        return $this->withPsrRequest($this->psrRequest->withProtocolVersion($version));
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getHeaders(): array
    {
        return $this->psrRequest->getHeaders();
    }

    /** Reports whether the wrapped request carries the named header, case-insensitively. */
    #[\Override]
    public function hasHeader($name): bool
    {
        return $this->psrRequest->hasHeader($name);
    }

    /**
     * Returns every value of the named header, one entry per value.
     *
     * An empty array when the header is not present, per PSR-7.
     */
    #[\Override]
    public function getHeader($name): array
    {
        return $this->psrRequest->getHeader($name);
    }

    /**
     * Returns the named header's values joined with commas.
     *
     * An empty string when the header is not present, per PSR-7.
     */
    #[\Override]
    public function getHeaderLine($name): string
    {
        return $this->psrRequest->getHeaderLine($name);
    }

    /**
     * Returns a clone with the named header replaced by the given value.
     *
     * Any existing values of that header are discarded. This request is left
     * untouched and the clone starts with an empty parameter cache.
     */
    #[\Override]
    public function withHeader($name, $value): static
    {
        return $this->withPsrRequest($this->psrRequest->withHeader($name, $value));
    }

    /**
     * Returns a clone with the given value appended to the named header.
     *
     * Existing values of that header are kept. This request is left untouched
     * and the clone starts with an empty parameter cache.
     */
    #[\Override]
    public function withAddedHeader($name, $value): static
    {
        return $this->withPsrRequest($this->psrRequest->withAddedHeader($name, $value));
    }

    /**
     * Returns a clone with the named header removed.
     *
     * Each call clones the whole wrapped request; use the private bulk
     * counterpart when removing several headers at once.
     */
    #[\Override]
    public function withoutHeader($name): static
    {
        return $this->withPsrRequest($this->psrRequest->withoutHeader($name));
    }

    /**
     * Returns the wrapped request's body stream.
     *
     * The live stream, not a copy: reading from it advances the position seen
     * by every other holder of this request.
     */
    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->psrRequest->getBody();
    }

    /**
     * Returns a clone whose body is the given stream.
     *
     * This request is left untouched; the clone starts with an empty
     * parameter cache, so parameters are re-derived from the new body.
     */
    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        return $this->withPsrRequest($this->psrRequest->withBody($body));
    }

    /**
     * Returns the request target as it appears on the request line.
     *
     * The explicitly set target when there is one, otherwise the origin-form
     * target derived from the URI, per PSR-7.
     */
    #[\Override]
    public function getRequestTarget(): string
    {
        return $this->psrRequest->getRequestTarget();
    }

    /**
     * Returns a clone whose request line carries the given target verbatim.
     *
     * The URI is not touched, so the target may diverge from it.
     */
    #[\Override]
    public function withRequestTarget($requestTarget): static
    {
        return $this->withPsrRequest($this->psrRequest->withRequestTarget($requestTarget));
    }

    /** Returns the HTTP method of the wrapped request, in the case it was received in. */
    #[\Override]
    public function getMethod(): string
    {
        return $this->psrRequest->getMethod();
    }

    /**
     * Returns a clone using the given HTTP method.
     *
     * The method is passed through as given; the wrapped PSR-7 request
     * rejects a syntactically invalid one.
     */
    #[\Override]
    public function withMethod($method): static
    {
        return $this->withPsrRequest($this->psrRequest->withMethod($method));
    }

    /** Returns the wrapped request's URI. */
    #[\Override]
    public function getUri(): UriInterface
    {
        return $this->psrRequest->getUri();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getServerParams(): array
    {
        return $this->psrRequest->getServerParams();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getCookieParams(): array
    {
        return $this->psrRequest->getCookieParams();
    }

    /**
     * @param array<string, mixed> $cookies
     */
    #[\Override]
    public function withCookieParams(array $cookies): static
    {
        return $this->withPsrRequest($this->psrRequest->withCookieParams($cookies));
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getQueryParams(): array
    {
        return $this->psrRequest->getQueryParams();
    }

    /**
     * @param array<string, mixed> $query
     */
    #[\Override]
    public function withQueryParams(array $query): static
    {
        return $this->withPsrRequest($this->psrRequest->withQueryParams($query));
    }

    /**
     * @return array<string, UploadedFileInterface|array<int|string, mixed>>
     */
    #[\Override]
    public function getUploadedFiles(): array
    {
        return $this->psrRequest->getUploadedFiles();
    }

    /**
     * @param array<string, UploadedFileInterface|array<int|string, mixed>> $uploadedFiles
     */
    #[\Override]
    public function withUploadedFiles(array $uploadedFiles): static
    {
        return $this->withPsrRequest($this->psrRequest->withUploadedFiles($uploadedFiles));
    }

    /**
     * @return array<string, mixed>|object|null
     */
    #[\Override]
    public function getParsedBody(): mixed
    {
        return $this->psrRequest->getParsedBody();
    }

    /**
     * @param array<string, mixed>|object|null $data
     */
    #[\Override]
    public function withParsedBody($data): static
    {
        return $this->withPsrRequest($this->psrRequest->withParsedBody($data));
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getAttributes(): array
    {
        return $this->psrRequest->getAttributes();
    }

    /**
     * Returns the named request attribute.
     *
     * `$default` is returned when no attribute of that name has been set,
     * which is how attributes put on the request by middleware are read
     * without knowing whether that middleware ran.
     */
    #[\Override]
    public function getAttribute($name, $default = null): mixed
    {
        return $this->psrRequest->getAttribute($name, $default);
    }

    /**
     * Returns a clone carrying the named request attribute.
     *
     * This request is left untouched, so middleware wanting the attribute to
     * be visible downstream has to pass the returned instance on.
     */
    #[\Override]
    public function withAttribute($name, $value): static
    {
        return $this->withPsrRequest($this->psrRequest->withAttribute($name, $value));
    }

    /**
     * Returns a clone with the named request attribute removed.
     *
     * A no-op clone when no attribute of that name is set.
     */
    #[\Override]
    public function withoutAttribute($name): static
    {
        return $this->withPsrRequest($this->psrRequest->withoutAttribute($name));
    }
}
