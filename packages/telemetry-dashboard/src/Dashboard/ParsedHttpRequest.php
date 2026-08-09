<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * The result of {@see HttpMessageParser::tryParse()} -- method, path, headers
 * (lower-cased names), and the fully-buffered request body.
 */
final class ParsedHttpRequest
{
    /** @param array<string,string> $headers header name (lower-case) => value */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * The value of header $name, matched case-insensitively, or null when the
     * request did not carry it.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
