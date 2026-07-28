# quioteframework/queue-redis

Redis-backed queue driver for [Quiote](https://github.com/quioteframework/quiote), built on [predis/predis](https://github.com/predis/predis).

`Quiote\Queue\Redis\RedisQueueDriver` implements `Quiote\Queue\PollableQueueDriverInterface`. Ready jobs live in a Redis LIST; `reserve()` atomically moves one into a `processing` LIST (the reliable-queue pattern, so an in-flight job survives a crashed worker); delayed/released jobs live in a ZSET scored by their due timestamp.

## Install

```
composer require quioteframework/queue-redis
```

Installing the package registers the `redis` queue driver alias automatically via `Quiote\Queue\Redis\QueueRedisPlugin`. Point `queue.default_driver` (or a scheduler/job's explicit driver) at `redis`, and configure:

```xml
<queue>
    <default_driver>redis</default_driver>
    <redis>
        <dsn>redis://127.0.0.1:6379</dsn>
        <prefix>quiote_queue</prefix>
    </redis>
</queue>
```

Or construct it directly:

```php
$driver = new \Quiote\Queue\Redis\RedisQueueDriver(new \Predis\Client('redis://127.0.0.1:6379'));
```

## License

MIT. See [LICENSE](LICENSE).
