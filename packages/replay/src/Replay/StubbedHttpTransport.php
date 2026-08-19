<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Http\HttpFingerprint;

/**
 * The isolated-replay counterpart to
 * {@see \Quiote\Replay\Http\RecordingHttpTransport}: never opens a socket,
 * never resolves a hostname, never touches the real network under any
 * circumstance -- in isolated mode there is no transport at all. Answers
 * every `sendRequest()` from an injected
 * {@see EffectLedger}, matching on the same {@see HttpFingerprint} scheme the
 * recorder used, and builds a real PSR-7 response from the recorded
 * status/headers/body via the injected PSR-17 factories.
 *
 * A ledger miss -- no recorded effect for this method+URI+body, or every
 * recorded effect for it has already been consumed -- raises rather than
 * fabricating a 200: inventing a response would fabricate a passing test.
 */
final class StubbedHttpTransport implements ClientInterface
{
    public function __construct(
        private readonly EffectLedger $ledger,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $fingerprint = HttpFingerprint::of($request);
        $effect = $this->ledger->match(EffectKind::Http, $fingerprint);
        if ($effect === null) {
            throw new \RuntimeException(sprintf(
                'StubbedHttpTransport: no recorded HTTP effect for "%s %s".',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        $result = $effect->result;
        if (!is_array($result) || !isset($result['status']) || !is_int($result['status'])) {
            throw new \RuntimeException(sprintf('StubbedHttpTransport: recorded effect for "%s" is not a valid HTTP response.', $fingerprint));
        }

        $response = $this->responseFactory->createResponse($result['status']);

        $headers = $result['headers'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $name => $values) {
                if (!is_string($name)) {
                    continue;
                }
                foreach ((is_array($values) ? $values : [$values]) as $value) {
                    if (is_string($value)) {
                        $response = $response->withAddedHeader($name, $value);
                    }
                }
            }
        }

        $body = $result['body'] ?? '';
        $response = $response->withBody($this->streamFactory->createStream(is_string($body) ? $body : ''));

        return $response;
    }
}
