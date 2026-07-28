<?php

declare(strict_types=1);

namespace Quiote\Runtime\RoadRunner;

use Psr\Http\Message\ResponseInterface;
use Quiote\Http\Sse\SseStream;
use Quiote\Runtime\Emitter\ResponseEmitterInterface;
use Spiral\RoadRunner\Http\PSR7Worker;

/**
 * Hands the response back to RoadRunner over the worker relay.
 *
 * An ordinary response goes through PSR7Worker::respond(), which serialises the
 * whole body into one payload. A streaming body instead goes to
 * HttpWorker::respond() with a Generator, which RoadRunner sends as a sequence
 * of frames -- one per yielded chunk, so an SSE event reaches the client as soon
 * as the action produces it rather than when the stream finally ends.
 *
 * The generator is driven off {@see SseStream::read()} rather than
 * PSR7Worker's own `chunkSize` property, which is marked @internal.
 */
final class RoadRunnerResponseEmitter implements ResponseEmitterInterface
{
    public const DEFAULT_CHUNK_SIZE = 8192;

    public function __construct(
        private readonly PSR7Worker $worker,
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
    }

    public function emit(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if (!$body instanceof SseStream) {
            $this->worker->respond($response);
            return;
        }

        $this->worker->getHttpWorker()->respond(
            $response->getStatusCode(),
            $this->stream($body),
            $response->getHeaders(),
        );
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * @return \Generator<int, string, mixed, string>
     */
    private function stream(SseStream $body): \Generator
    {
        $chunkSize = max(1, $this->chunkSize);

        while (!$body->eof()) {
            $chunk = $body->read($chunkSize);
            if ($chunk !== '') {
                yield $chunk;
            }
        }

        // RoadRunner sends a generator's return value as the closing frame; the
        // stream is already fully drained, so there is nothing left to flush.
        return '';
    }
}
