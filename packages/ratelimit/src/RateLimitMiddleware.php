<?php

namespace Quiote\Security\RateLimit;

use Quiote\Http\Psr17;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Http\ProblemDetails;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * General-purpose per-client HTTP rate limiting, built on the same
 * symfony/rate-limiter primitives as {@see LoginThrottle} but keyed by client
 * IP rather than a login identifier. Runs in the `pre_routing` phase so an
 * over-limit request is rejected before any route resolution work happens.
 * Opt-in via `ratelimit.http.enabled` — a fresh app has no rate-limit storage
 * configured, so this stays off until an app explicitly turns it on (and,
 * typically, binds a persistent {@see StorageInterface} such as
 * {@see PdoRateLimiterStorage} in place of the in-memory default).
 * The client key is the connecting peer's address
 * (`$_SERVER['REMOTE_ADDR']`), not `X-Forwarded-For`, unless
 * `ratelimit.http.trust_forwarded_for` is explicitly enabled — trusting a
 * client-supplied header by default would let any caller spoof a fresh key
 * and bypass the limit entirely. When it is enabled, the address is read from the
 * right of the header per `ratelimit.http.trusted_proxy_hops` (default 1), which
 * is the part a trusted proxy wrote rather than the part the client did. */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'pre_routing', priority: 10)]
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * Consumes one token for the calling client and rejects the request when
     * the limit is exhausted.
     *
     * Passes straight through when `ratelimit.http.enabled` is off. Otherwise a
     * limiter is built per request from the configured policy, limit and window
     * over the injected storage, and keyed by the client address. An accepted
     * request continues down the pipeline; a rejected one short-circuits with a
     * 429 problem-details response carrying `Retry-After`, so the route is
     * never resolved.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Config::getBool('ratelimit.http.enabled', false)) {
            return $handler->handle($request);
        }

        $factory = new RateLimiterFactory([
            'id' => 'quiote_http',
            'policy' => Config::getString('ratelimit.http.policy', 'sliding_window'),
            'limit' => max(1, Config::getInt('ratelimit.http.max_requests', 60)),
            'interval' => Config::getString('ratelimit.http.window', '1 minute'),
        ], $this->storage);

        $limit = $factory->create($this->clientKey($request))->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->unixTimestamp());
            $problem = ProblemDetails::create(
                status: 429,
                detail: 'Too many requests. Retry after ' . $retryAfter . ' second(s).',
            );
            $psr17 = Psr17::factory();
            return $psr17->createResponse(429)
                ->withHeader('Content-Type', ProblemDetails::MEDIA_TYPE)
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withBody($psr17->createStream($problem->toJson()));
        }

        return $handler->handle($request);
    }

    /**
     * The key this request is throttled under.
     *
     * With `trust_forwarded_for` on, the address is taken from the RIGHT of
     * `X-Forwarded-For`, skipping `ratelimit.http.trusted_proxy_hops` entries (one
     * by default). A proxy appends the peer it saw, so the list reads
     * `client, proxy1, proxy2` with the trustworthy entries at the end: taking the
     * leftmost value meant taking the one the client wrote, which an attacker just
     * varies per request -- enabling the option bought exactly zero throttling.
     * Counting from the right yields the address the outermost proxy we trust
     * actually observed.
     */
    private function clientKey(ServerRequestInterface $request): string
    {
        if (Config::getBool('ratelimit.http.trust_forwarded_for', false)) {
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $hops = max(1, Config::getInt('ratelimit.http.trusted_proxy_hops', 1));
                $parts = array_values(array_filter(
                    array_map('trim', explode(',', $forwarded)),
                    static fn(string $part): bool => $part !== '',
                ));
                if ($parts !== []) {
                    // One trusted hop => the last entry was written by that proxy and
                    // names its peer, so it is the rightmost value we can believe.
                    $index = count($parts) - $hops;
                    $candidate = $parts[max(0, $index)];

                    // Only if it is actually an address. Entries to the left of the
                    // trusted hops are client-written, and a chain shorter than
                    // trusted_proxy_hops (a misconfigured hop count, or a request
                    // that did not traverse every expected proxy) makes the clamp
                    // above land on one of them. That value goes straight into a
                    // limiter cache key, so accepting arbitrary text lets a caller
                    // mint unbounded distinct keys and exhaust the storage backend.
                    if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                        return $candidate;
                    }
                }
            }
        }

        $server = $request->getServerParams();
        return is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : 'unknown';
    }
}
