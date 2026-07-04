<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * Minimal, bounded HTTP/1.1 request parser for the dashboard's OTLP receiver
 * (see {@see OtlpReceiver}). This is deliberately NOT a general HTTP parser:
 * the OTel PHP OTLP/HTTP exporter always sends `POST /v1/traces` or
 * `POST /v1/metrics` with a `Content-Length` header (never chunked transfer
 * encoding), so that is the only shape this class needs to accept. Anything
 * else -- chunked encoding, a missing/invalid Content-Length, an oversized
 * header block or body, a malformed request/header line -- throws
 * {@see MalformedRequestException} so the receiver can reject the connection
 * and keep serving everyone else, mirroring the "never crash the process"
 * posture the telemetry middleware already holds on the app side.
 *
 * Usage: one instance per connection. Feed it raw bytes as they arrive via
 * {@see feed()}, then call {@see tryParse()} after every feed; it returns
 * null until a complete request has been buffered, and consumes exactly one
 * request's bytes off the internal buffer per call (so pipelined/back-to-back
 * requests on the same connection are handled by calling tryParse() again).
 */
final class HttpMessageParser
{
    private const MAX_HEADER_BYTES = 8192;
    private const MAX_BODY_BYTES = 32 * 1024 * 1024;

    private string $buffer = '';

    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;

        if (strlen($this->buffer) > self::MAX_HEADER_BYTES + self::MAX_BODY_BYTES) {
            throw new MalformedRequestException('Request exceeds the maximum allowed size.');
        }
    }

    /**
     * @return ParsedHttpRequest|null null means "need more bytes", call
     *         feed() again and retry
     */
    public function tryParse(): ?ParsedHttpRequest
    {
        $headerEnd = strpos($this->buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            if (strlen($this->buffer) > self::MAX_HEADER_BYTES) {
                throw new MalformedRequestException('Request headers exceed the maximum allowed size.');
            }
            return null;
        }

        [$method, $path, $headers] = $this->parseHead(substr($this->buffer, 0, $headerEnd));

        $contentLength = $this->contentLength($headers);
        $bodyStart = $headerEnd + 4;

        if (strlen($this->buffer) < $bodyStart + $contentLength) {
            return null;
        }

        $body = substr($this->buffer, $bodyStart, $contentLength);
        $this->buffer = substr($this->buffer, $bodyStart + $contentLength);

        return new ParsedHttpRequest($method, $path, $headers, $body);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string,string>}
     */
    private function parseHead(string $headBlock): array
    {
        $lines = explode("\r\n", $headBlock);
        $requestLine = array_shift($lines);

        if (!preg_match('#^(GET|POST) (\S+) HTTP/1\.[01]$#', (string) $requestLine, $m)) {
            throw new MalformedRequestException('Unsupported or malformed request line.');
        }

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new MalformedRequestException('Malformed header line.');
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = $value;
        }

        return [$m[1], $m[2], $headers];
    }

    /** @param array<string,string> $headers */
    private function contentLength(array $headers): int
    {
        if (isset($headers['transfer-encoding'])) {
            throw new MalformedRequestException('Chunked transfer encoding is not supported.');
        }

        if (!isset($headers['content-length'])) {
            return 0;
        }

        if (!ctype_digit($headers['content-length'])) {
            throw new MalformedRequestException('Invalid Content-Length header.');
        }

        $length = (int) $headers['content-length'];
        if ($length > self::MAX_BODY_BYTES) {
            throw new MalformedRequestException('Content-Length exceeds the maximum allowed body size.');
        }

        return $length;
    }
}
