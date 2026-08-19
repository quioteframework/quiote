<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Replay\EffectLedger;

/**
 * The in-flight buffer for one request: bounded by `replay.max_bytes` and
 * `replay.max_effects`, so a request with an unusually large body or an
 * unusually long effect ledger produces a cassette that says it was
 * truncated rather than growing without bound.
 *
 * Holds an {@see EffectLedger} for whatever's wired to append to it -- DB
 * effects are wired into a live request automatically by whichever
 * `quioteframework/replay-{propulsion,doctrine,eloquent,cycle}` plugin is
 * installed; cache/queue/env effects still need the app to substitute the
 * matching `Recording*` decorator for its own cache/queue/env binding by
 * hand.
 */
final class RecordingSession
{
    private int $bytesUsed = 0;
    private bool $requestBodyTruncated = false;
    private bool $responseBodyTruncated = false;

    /** @var array<string, mixed>|null */
    private ?array $request = null;

    /** @var array<string, mixed>|null */
    private ?array $sessionBefore = null;

    /** @var array<string, mixed>|null */
    private ?array $sessionAfter = null;

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var array<string, mixed>|null */
    private ?array $response = null;

    /** @var array<string, mixed>|null */
    private ?array $exception = null;

    private readonly EffectLedger $ledger;

    public function __construct(
        private readonly int $maxBytes = 2_097_152,
        private readonly int $maxEffects = 2000,
        ?EffectLedger $ledger = null,
    ) {
        $this->ledger = $ledger ?? new EffectLedger();
    }

    public function ledger(): EffectLedger
    {
        return $this->ledger;
    }

    /**
     * @param array<string, mixed> $request {method, uri, protocol, headers, cookies, body,
     *        uploads, server}
     */
    public function setRequest(array $request): void
    {
        [$bounded, $truncated] = $this->boundBody($request);
        $this->request = $bounded;
        $this->requestBodyTruncated = $truncated;
    }

    /** @param array<string, mixed> $response {status, headers, body, stray_output} */
    public function setResponse(array $response): void
    {
        [$bounded, $truncated] = $this->boundBody($response);
        $this->response = $bounded;
        $this->responseBodyTruncated = $truncated;
    }

    /** @param array<string, mixed>|null $session */
    public function setSessionBefore(?array $session): void
    {
        $this->sessionBefore = $session;
    }

    /** @param array<string, mixed>|null $session */
    public function setSessionAfter(?array $session): void
    {
        $this->sessionAfter = $session;
    }

    /** @param array<string, mixed> $resolved */
    public function setResolved(array $resolved): void
    {
        $this->resolved = $resolved;
    }

    /** @param array<string, mixed>|null $exception */
    public function setException(?array $exception): void
    {
        $this->exception = $exception;
    }

    /** @return array<string, mixed>|null */
    public function request(): ?array
    {
        return $this->request;
    }

    /** @return array<string, mixed>|null */
    public function response(): ?array
    {
        return $this->response;
    }

    /** @return array<string, mixed>|null */
    public function sessionBefore(): ?array
    {
        return $this->sessionBefore;
    }

    /** @return array<string, mixed>|null */
    public function sessionAfter(): ?array
    {
        return $this->sessionAfter;
    }

    /** @return array<string, mixed> */
    public function resolved(): array
    {
        return $this->resolved;
    }

    /** @return array<string, mixed>|null */
    public function exception(): ?array
    {
        return $this->exception;
    }

    public function requestBodyTruncated(): bool
    {
        return $this->requestBodyTruncated;
    }

    public function responseBodyTruncated(): bool
    {
        return $this->responseBodyTruncated;
    }

    /** Whether the ledger holds more effects than `replay.max_effects` allows into the cassette. */
    public function effectsTruncated(): bool
    {
        return count($this->ledger->all()) > $this->maxEffects;
    }

    /** @return list<Effect> The ledger's effects, bounded to `replay.max_effects`, in recorded order. */
    public function boundedEffects(): array
    {
        if ($this->maxEffects <= 0) {
            return [];
        }

        return array_slice($this->ledger->all(), 0, $this->maxEffects);
    }

    /**
     * Charges a request/response section's body against the session's shared byte budget. A
     * body that would push the total over `maxBytes` is truncated to whatever budget remains
     * (possibly to nothing) and the section's `body.truncated` flag is set, rather than growing
     * the cassette unboundedly.
     *
     * @param array<string, mixed> $section
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function boundBody(array $section): array
    {
        $body = $section['body'] ?? null;
        if (!is_array($body) || !isset($body['content']) || !is_string($body['content'])) {
            return [$section, false];
        }

        $content = $body['content'];
        $length = strlen($content);
        $remaining = $this->maxBytes - $this->bytesUsed;

        if ($remaining <= 0) {
            $body['content'] = '';
            $body['truncated'] = true;
            $section['body'] = $body;

            return [$section, true];
        }

        if ($length <= $remaining) {
            $this->bytesUsed += $length;

            return [$section, false];
        }

        $body['content'] = substr($content, 0, $remaining);
        $body['truncated'] = true;
        $section['body'] = $body;
        $this->bytesUsed += $remaining;

        return [$section, true];
    }
}
