# quioteframework/csrf

CSRF protection middleware for [Quiote](https://github.com/quioteframework/quiote), built on `symfony/security-csrf`.

## Install

```
composer require quioteframework/csrf
```

## Enable

Nothing to do — this package is a mandatory dependency of the Quiote kernel and its plugin registers itself automatically. Every app is CSRF-protected by default.

To disable, set `core.csrf.enabled` to `false` in your app's settings, or remove this package entirely (the kernel degrades gracefully if it's absent).

## License

MIT. See [LICENSE](LICENSE).
