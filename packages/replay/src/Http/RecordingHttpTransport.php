<?php

declare(strict_types=1);

namespace Quiote\Replay\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * A decorating PSR-18 transport: wraps a real inner `ClientInterface`
 * (typically `Quiote\Http\Client\CurlTransport`, installed as the `$transport`
 * `Quiote\Http\Client\HttpClient` is constructed with) and appends one
 * {@see EffectKind::Http} entry per successful call to an injected
 * {@see EffectLedger}, returning the real response completely untouched to
 * the caller.
 *
 * Recording happens once per actual transport-level attempt, not once per
 * logical `HttpClient::sendRequest()` call: `HttpClient::sendWithRetry()`
 * calls `$this->transport->sendRequest($request)` again for each retry, so a
 * request retried twice produces up to three ledger entries -- which is the
 * honest record of what actually happened on the wire, and lets a replay
 * reproduce a retry sequence exactly rather than collapsing it into one call.
 *
 * A call whose inner transport throws a `ClientExceptionInterface` records
 * nothing and lets the exception propagate: a failed call has no response to
 * replay, and no ledger entry is a more honest state than a fabricated one --
 * same rule `Quiote\Replay\Db\RecordingPdo` follows for a failed statement.
 */
final class RecordingHttpTransport implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $transport,
        private readonly EffectLedger $ledger,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $start = $this->clock->monotonic();
        $response = $this->transport->sendRequest($request);

        $this->ledger->record(
            EffectKind::Http,
            HttpFingerprint::of($request),
            [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
            ],
            $this->resultOf($response),
            self::durationMicros($this->clock, $start),
        );

        return $response;
    }

    /** @return array<string, mixed> */
    private function resultOf(ResponseInterface $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => HttpFingerprint::captureBody($response->getBody()),
        ];
    }

    /** @return non-negative-int */
    private static function durationMicros(ClockInterface $clock, float $startMonotonicSeconds): int
    {
        return max(0, (int) round(($clock->monotonic() - $startMonotonicSeconds) * 1_000_000));
    }
}
