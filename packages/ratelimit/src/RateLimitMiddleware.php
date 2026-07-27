<?php

namespace Quiote\Security\RateLimit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Http\ProblemDetails;
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
 * and bypass the limit entirely. */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'pre_routing', priority: 10)]
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly StorageInterface $storage)
    {
    }

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
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $problem = ProblemDetails::create(
                status: 429,
                detail: 'Too many requests. Retry after ' . $retryAfter . ' second(s).',
            );
            $psr17 = new Psr17Factory();
            return $psr17->createResponse(429)
                ->withHeader('Content-Type', ProblemDetails::MEDIA_TYPE)
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withBody($psr17->createStream($problem->toJson()));
        }

        return $handler->handle($request);
    }

    private function clientKey(ServerRequestInterface $request): string
    {
        if (Config::getBool('ratelimit.http.trust_forwarded_for', false)) {
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                return trim(explode(',', $forwarded)[0]);
            }
        }

        $server = $request->getServerParams();
        return is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : 'unknown';
    }
}
