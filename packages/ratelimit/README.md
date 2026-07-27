# quioteframework/ratelimit

Rate limiting (login throttle, PDO-backed limiter storage) for [Quiote](https://github.com/quioteframework/quiote), built on `symfony/rate-limiter`.

## Install

```
composer require quioteframework/ratelimit
```

## Use as a plain library (login throttle)

No `plugins` config entry needed. Instantiate directly where you need it:

```php
use Quiote\Security\RateLimit\LoginThrottle;
use Quiote\Security\RateLimit\PdoRateLimiterStorage;

$throttle = new LoginThrottle(new PdoRateLimiterStorage($pdo));
```

## General HTTP rate limiting (middleware)

`RateLimitMiddleware` throttles requests per client IP. It's registered by
`RateLimitPlugin` and opt-in via `ratelimit.http.enabled`:

- `ratelimit.http.enabled` — default `false`
- `ratelimit.http.max_requests` — default `60`
- `ratelimit.http.window` — default `"1 minute"`
- `ratelimit.http.policy` — `sliding_window` / `fixed_window` / `token_bucket`, default `sliding_window`
- `ratelimit.http.trust_forwarded_for` — trust `X-Forwarded-For` for the client key instead of `REMOTE_ADDR`, default `false` (only enable behind a trusted reverse proxy)

Limiter state defaults to an in-memory store (`Symfony\Component\RateLimiter\Storage\InMemoryStorage`,
reset every process/worker restart). Bind `PdoRateLimiterStorage` as the
`Symfony\Component\RateLimiter\Storage\StorageInterface` service instead for
state shared across workers and processes. An over-limit request gets a 429
`application/problem+json` response with a `Retry-After` header.

## License

MIT. See [LICENSE](LICENSE).
