<?php
namespace Quiote\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Quiote\Response\WebResponse;

/**
 * Thin PSR-7 ResponseInterface adapter around WebResponse (Phase 1).
 * Immutable-ish: mutation methods return $this for now (no deep cloning) – acceptable for bridge stage.
 */
class PsrResponseAdapter implements ResponseInterface
{
    public function __construct(private readonly WebResponse $legacy, private ?StreamInterface $body = null, private string $protocolVersion = '1.1') {}

    /**
     * Expose underlying legacy response for bridge/testing.
     */
    public function getLegacy(): WebResponse { return $this->legacy; }

    // Status
    public function getStatusCode(): int { return (int) $this->legacy->getHttpStatusCode(); }
    public function withStatus($code, $reasonPhrase = ''): static { $this->legacy->setHttpStatusCode($code); return $this; }
    public function getReasonPhrase(): string { return ''; }

    // Protocol
    public function getProtocolVersion(): string { return $this->protocolVersion; }
    public function withProtocolVersion($version): static { $this->protocolVersion = $version; return $this; }

    // Headers – map using legacy get/set header methods
    public function getHeaders(): array { $all = []; foreach($this->legacy->getHttpHeaders() as $name => $vals) { $all[$name] = (array)$vals; } return $all; }
    public function hasHeader($name): bool { return $this->legacy->hasHttpHeader($name); }
    public function getHeader($name): array { $vals = $this->legacy->getHttpHeader($name); return $vals === null ? [] : (array)$vals; }
    public function getHeaderLine($name): string { return implode(', ', $this->getHeader($name)); }
    public function withHeader($name, $value): static { $this->legacy->setHttpHeader($name, is_array($value)? $value:[$value]); return $this; }
    public function withAddedHeader($name, $value): static { $this->legacy->setHttpHeader($name, is_array($value)? $value:[$value], false); return $this; }
    public function withoutHeader($name): static { $this->legacy->removeHttpHeader($name); return $this; }

    // Body
    public function getBody(): StreamInterface { if(!$this->body) { $this->body = SimpleStream::fromString((string)$this->legacy->getContent()); } return $this->body; }
    public function withBody(StreamInterface $body): static { $this->body = $body; return $this; }
}
