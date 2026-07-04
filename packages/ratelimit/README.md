# quioteframework/ratelimit

Rate limiting (login throttle, PDO-backed limiter storage) for [Quiote](https://github.com/quioteframework/quiote), built on `symfony/rate-limiter`.

## Install

```
composer require quioteframework/ratelimit
```

## Use

A plain library, not a plugin — no `plugins` config entry needed. Instantiate directly where you need it:

```php
use Quiote\Security\RateLimit\LoginThrottle;
use Quiote\Security\RateLimit\PdoRateLimiterStorage;

$throttle = new LoginThrottle(new PdoRateLimiterStorage($pdo));
```

## License

MIT. See [LICENSE](LICENSE).
