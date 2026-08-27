# quioteframework/exception-notifier

Exception notification plugin for [Quiote](https://github.com/quioteframework/quiote):
Microsoft Teams (Adaptive Card) and generic webhook channels, listening on the
`ExceptionCaughtEvent` that `ErrorHandlingMiddleware` already emits for every
caught exception.

## Install

```
composer require quioteframework/exception-notifier
```

## Enable

Opt-in: `exception_notifier.enabled` defaults to `false`. Configurable keys:

- `exception_notifier.enabled` — turn notifications on (default `false`)
- `exception_notifier.min_status` — only notify for exceptions mapped to this HTTP status or higher (default `500`)
- `exception_notifier.throttle_seconds` — suppress a repeat notification for the same exception class+message within this window (default `60`, `0` disables throttling)
- `exception_notifier.ignore` — exception class names to never notify for, subclasses included
- `exception_notifier.channels` — list of channel configs, each an array with at least `driver` and `webhook_url`:

```php
'exception_notifier' => [
    'enabled' => true,
    'channels' => [
        ['driver' => 'teams', 'name' => 'teams-ops', 'webhook_url' => '...'],
        ['driver' => 'webhook', 'name' => 'generic', 'webhook_url' => '...', 'headers' => ['X-Api-Key' => '...']],
    ],
],
```

Each channel entry also accepts `enabled` (default `true`) to disable it without removing it.

## Extending with a custom channel

Implement `NotifierChannelInterface` (a `notify()` method) and
`NotifierChannelFactoryInterface` (a static `fromChannelConfig()` factory),
then register the class under a driver alias — from your own plugin's
`register()`, no change to this package required:

```php
ExceptionNotifierChannelRegistry::register('slack', SlackNotifierChannel::class);
```

## License

MIT. See [LICENSE](LICENSE).
