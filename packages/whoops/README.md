# quioteframework/whoops

Full-detail developer exception renderer for [Quiote](https://github.com/quioteframework/quiote), built on [filp/whoops](https://github.com/filp/whoops). Only ever used when `core.developer_exceptions` is explicitly enabled.

## Install

```
composer require quioteframework/whoops
```

## Enable

Add the plugin to your app's `plugins` config key:

```php
'plugins' => [\Quiote\Exception\Rendering\Whoops\WhoopsPlugin::class],
```

...and set `core.developer_exceptions` to `true` in a development environment. Without either, exceptions render via the framework's generic `SafeRenderer` instead.

## License

MIT. See [LICENSE](LICENSE).
