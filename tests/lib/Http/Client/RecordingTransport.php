<?php

namespace Quiote\Test\Http\Client;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Http\Client\Exception\NetworkException;

/**
 * In-memory PSR-18 transport test double: records every request it receives and
 * replays a scripted sequence of responses (or a NetworkException for a queued
 * `null`), so HttpClient's base-URI/default-header/retry/telemetry logic can be
 * tested deterministically without real sockets. Lives in tests/lib (classmapped)
 * so multiple test files can share it.
 */
final class RecordingTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|null> queued outcomes; null => throw NetworkException */
    private array $script;

    private int $index = 0;

    public function __construct(ResponseInterface|int|null ...$outcomes)
    {
        $this->script = array_map(
            static fn($o) => is_int($o) ? new Response($o) : $o,
            $outcomes ?: [new Response(200)],
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $outcome = $this->script[min($this->index, count($this->script) - 1)];
        $this->index++;
        if ($outcome === null) {
            throw new NetworkException('simulated network failure', $request);
        }
        return $outcome;
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->requests[array_key_last($this->requests)] ?? null;
    }
}
