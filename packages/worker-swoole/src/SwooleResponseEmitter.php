<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

use Psr\Http\Message\ResponseInterface;
use Quiote\Http\Sse\SseStream;
use Quiote\Runtime\Emitter\ResponseEmitterInterface;

/**
 * Writes the response to Swoole's per-request response object.
 *
 * Constructed per request, unlike the SAPI and RoadRunner emitters, because the
 * thing being written to is the current request's response.
 *
 * Swoole's write() returning false is this runtime's client-disconnect signal --
 * the equivalent of connection_aborted() under a SAPI, which always reports 0
 * under the CLI and so cannot be used here. Without it an endless SSE generator
 * would keep producing events for a client that has already gone.
 */
final class SwooleResponseEmitter implements ResponseEmitterInterface
{
    public function __construct(private readonly SwooleResponseWriterInterface $writer)
    {
    }

    /**
     * Writes status, headers and body onto Swoole's response object.
     *
     * A header with several values is passed as an array so repeated names
     * (Set-Cookie) survive. For an {@see SseStream} body, Content-Length is
     * dropped and the body is written chunk by chunk until the stream ends or
     * the client goes away, with a final `end()` closing the response either
     * way.
     */
    public function emit(ResponseInterface $response): void
    {
        $body = $response->getBody();
        $streaming = $body instanceof SseStream;

        $this->writer->status($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            $name = (string) $name;
            if ($streaming && strtolower($name) === 'content-length') {
                // A streamed body has no length known up front, and announcing a
                // wrong one truncates the stream.
                continue;
            }
            // The array form is what keeps repeated headers (Set-Cookie) from
            // overwriting each other.
            $this->writer->header($name, count($values) === 1 ? $values[0] : array_values($values));
        }

        if ($streaming) {
            $this->stream($body);
            return;
        }

        $this->writer->end((string) $body);
    }

    /** Always true: Swoole's write() delivers a chunk without buffering the whole body. */
    public function supportsStreaming(): bool
    {
        return true;
    }

    private function stream(SseStream $body): void
    {
        $body->writeTo(fn(string $chunk): bool => $this->writer->write($chunk));
        $this->writer->end();
    }
}
