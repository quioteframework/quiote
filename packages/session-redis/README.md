# quioteframework/session-redis

Redis-backed session storage for [Quiote](https://github.com/quioteframework/quiote), built on [predis/predis](https://github.com/predis/predis).

**`Quiote\Session\Redis\RedisSessionPersistence`** implements `Quiote\Session\SessionPersistenceInterface` (`load`/`save`/`delete`), the backend for `Quiote\Session\SessionManager` — the modern, PSR-7-based session mechanism, safe under persistent worker runtimes (FrankenPHP, RoadRunner).

## Install

```
composer require quioteframework/session-redis
```

## Usage

```php
$manager = new \Quiote\Session\SessionManager(
    new \Quiote\Session\Redis\RedisSessionPersistence(
        new \Predis\Client('redis://127.0.0.1:6379'),
        prefix: 'session:',
        ttl: 1440, // seconds; Redis expires the key itself, no GC pass needed
    ),
);
```

## License

MIT. See [LICENSE](LICENSE).
