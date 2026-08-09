<?php
namespace Quiote\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Quiote\Response\WebResponse;

/**
 * A PSR-7 view of a {@see WebResponse}, so a view or action handed a PSR-7 response can read
 * the status, headers and body the framework has assembled.
 *
 * Immutable, as PSR-7 requires: each with*() method returns a new adapter carrying the change
 * and leaves both this instance and the underlying WebResponse untouched. A caller that
 * discards the return value therefore changes nothing -- capture and propagate it, exactly as
 * with any PSR-7 message.
 *
 * To change the response the framework will actually send, write to the WebResponse itself
 * (`$this->getResponse()->setHttpHeader(...)` from a view, or {@see getLegacy()} here). That
 * is the mutable object; this is a value read off it.
 *
 * Values not yet overridden are read through to the WebResponse, so an adapter created before
 * the response was finished still reflects it.
 */
class PsrResponseAdapter implements ResponseInterface
{
    /**
     * Header overrides applied through with*(), or null while this adapter still reads every
     * header from the WebResponse.
     * @var ?array<string, list<string>>
     */
    private ?array $headerOverlay = null;

    /** Status override applied through withStatus(), or null to read from the WebResponse. */
    private ?int $statusOverlay = null;

    private ?string $reasonPhraseOverlay = null;

    public function __construct(private readonly WebResponse $legacy, private ?StreamInterface $body = null, private string $protocolVersion = '1.1') {}

    /**
     * The underlying mutable response. Write here to change what gets sent.
     */
    public function getLegacy(): WebResponse { return $this->legacy; }

    // Status
    /** Returns the overridden status code, or the WebResponse's current one when withStatus() was never called. */
    public function getStatusCode(): int
    {
        return $this->statusOverlay ?? (int) $this->legacy->getHttpStatusCode();
    }

    /**
     * Returns a clone whose status code overrides the WebResponse's, leaving the WebResponse itself alone.
     *
     * An empty reason phrase clears any override, so {@see getReasonPhrase()} falls back to the
     * standard phrase for the code.
     *
     * @throws \InvalidArgumentException when the code is outside the range HttpStatus accepts
     */
    public function withStatus($code, $reasonPhrase = ''): static
    {
        $code = (int) $code;
        if (!HttpStatus::isValid($code)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid HTTP status code: %d (expected %d-%d)',
                $code,
                HttpStatus::MIN,
                HttpStatus::MAX,
            ));
        }

        $new = clone $this;
        $new->statusOverlay = $code;
        $new->reasonPhraseOverlay = $reasonPhrase !== '' ? (string) $reasonPhrase : null;

        return $new;
    }

    /** Returns the phrase supplied to withStatus(), or the standard phrase HttpStatus maps the current code to. */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhraseOverlay ?? HttpStatus::phrase($this->getStatusCode());
    }

    // Protocol
    /** Returns the protocol version this adapter carries, defaulting to `1.1` unless the constructor or withProtocolVersion() set another. */
    public function getProtocolVersion(): string { return $this->protocolVersion; }

    /** Returns a clone carrying the given protocol version; the WebResponse is unaffected. */
    public function withProtocolVersion($version): static
    {
        $new = clone $this;
        $new->protocolVersion = (string) $version;

        return $new;
    }

    // Headers
    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        if ($this->headerOverlay !== null) {
            return $this->headerOverlay;
        }

        $all = [];
        foreach ($this->legacy->getHttpHeaders() as $name => $values) {
            $all[(string) $name] = self::normalizeValues($values);
        }

        return $all;
    }

    /** Reports whether a header is present, matching the name case-insensitively against the overlay or the WebResponse. */
    public function hasHeader($name): bool
    {
        return $this->findHeaderName((string) $name) !== null;
    }

    /**
     * @return list<string>
     */
    public function getHeader($name): array
    {
        $headers = $this->getHeaders();
        $found = $this->findHeaderName((string) $name);

        return $found !== null ? $headers[$found] : [];
    }

    /** Returns the header's values joined by `, `, or an empty string when the header is absent. */
    public function getHeaderLine($name): string { return implode(', ', $this->getHeader($name)); }

    /**
     * Returns a clone in which the named header is replaced by the given value or values.
     *
     * The clone snapshots the WebResponse's headers into its own overlay, so it no longer reads
     * headers through to the WebResponse. Any existing header with a differently cased name is
     * removed first, and the supplied spelling is the one stored. Non-stringable values become
     * empty strings.
     */
    public function withHeader($name, $value): static
    {
        $new = clone $this;
        $new->headerOverlay = $this->getHeaders();
        $existing = $this->findHeaderName((string) $name);
        if ($existing !== null) {
            unset($new->headerOverlay[$existing]);
        }
        $new->headerOverlay[(string) $name] = self::normalizeValues($value);

        return $new;
    }

    /**
     * Returns a clone with the given value or values appended to the named header.
     *
     * The clone snapshots the WebResponse's headers into its own overlay. An existing header is
     * matched case-insensitively and keeps its stored spelling; otherwise the supplied spelling
     * starts a new entry.
     */
    public function withAddedHeader($name, $value): static
    {
        $new = clone $this;
        $new->headerOverlay = $this->getHeaders();
        $existing = $this->findHeaderName((string) $name) ?? (string) $name;
        $new->headerOverlay[$existing] = array_merge(
            $new->headerOverlay[$existing] ?? [],
            self::normalizeValues($value)
        );

        return $new;
    }

    /**
     * Returns a clone without the named header, matching case-insensitively.
     *
     * When the header is absent this instance is returned unchanged, so no overlay is created
     * and the adapter keeps reading headers through to the WebResponse.
     */
    public function withoutHeader($name): static
    {
        $existing = $this->findHeaderName((string) $name);
        if ($existing === null) {
            return $this;
        }

        $new = clone $this;
        $new->headerOverlay = $this->getHeaders();
        unset($new->headerOverlay[$existing]);

        return $new;
    }

    /**
     * The stored spelling of $name, or null when the header is absent. HTTP header names are
     * case-insensitive, so a lookup must not depend on how the setter spelled it.
     */
    private function findHeaderName(string $name): ?string
    {
        $lower = strtolower($name);
        foreach (array_keys($this->getHeaders()) as $stored) {
            if (strtolower((string) $stored) === $lower) {
                return (string) $stored;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function normalizeValues(mixed $value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = is_scalar($item) || $item instanceof \Stringable ? (string) $item : '';
        }

        return $normalized;
    }

    // Body
    /**
     * Returns the body stream, building one from the WebResponse's content on first call.
     *
     * A resource is wrapped directly; null and scalar content are copied into an in-memory
     * stream. The result is memoised on this instance, so later changes to the WebResponse's
     * content are not picked up.
     *
     * @throws \RuntimeException when the content is neither a resource, null, nor scalar
     */
    public function getBody(): StreamInterface
    {
        if (!$this->body) {
            $content = $this->legacy->getContent();
            $this->body = match (true) {
                is_resource($content) => new SimpleStream($content),
                $content === null => SimpleStream::fromString(''),
                is_string($content) => SimpleStream::fromString($content),
                is_scalar($content) => SimpleStream::fromString((string) $content),
                default => throw new \RuntimeException(sprintf('Cannot convert WebResponse content of type "%s" into a PSR-7 stream body.', get_debug_type($content))),
            };
        }
        return $this->body;
    }

    /** Returns a clone carrying the given body stream; the WebResponse's own content is left untouched. */
    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->body = $body;

        return $new;
    }
}
