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
        return $new;
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->psrRequest->getProtocolVersion();
    }

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

    #[\Override]
    public function hasHeader($name): bool
    {
        return $this->psrRequest->hasHeader($name);
    }

    #[\Override]
    public function getHeader($name): array
    {
        return $this->psrRequest->getHeader($name);
    }

    #[\Override]
    public function getHeaderLine($name): string
    {
        return $this->psrRequest->getHeaderLine($name);
    }

    #[\Override]
    public function withHeader($name, $value): static
    {
        return $this->withPsrRequest($this->psrRequest->withHeader($name, $value));
    }

    #[\Override]
    public function withAddedHeader($name, $value): static
    {
        return $this->withPsrRequest($this->psrRequest->withAddedHeader($name, $value));
    }

    #[\Override]
    public function withoutHeader($name): static
    {
        return $this->withPsrRequest($this->psrRequest->withoutHeader($name));
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->psrRequest->getBody();
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        return $this->withPsrRequest($this->psrRequest->withBody($body));
    }

    #[\Override]
    public function getRequestTarget(): string
    {
        return $this->psrRequest->getRequestTarget();
    }

    #[\Override]
    public function withRequestTarget($requestTarget): static
    {
        return $this->withPsrRequest($this->psrRequest->withRequestTarget($requestTarget));
    }

    #[\Override]
    public function getMethod(): string
    {
        return $this->psrRequest->getMethod();
    }

    #[\Override]
    public function withMethod($method): static
    {
        return $this->withPsrRequest($this->psrRequest->withMethod($method));
    }

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

    #[\Override]
    public function getAttribute($name, $default = null): mixed
    {
        return $this->psrRequest->getAttribute($name, $default);
    }

    #[\Override]
    public function withAttribute($name, $value): static
    {
        return $this->withPsrRequest($this->psrRequest->withAttribute($name, $value));
    }

    #[\Override]
    public function withoutAttribute($name): static
    {
        return $this->withPsrRequest($this->psrRequest->withoutAttribute($name));
    }
}
