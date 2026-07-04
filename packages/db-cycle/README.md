# quioteframework/db-cycle

Cycle ORM driver adapter for [Quiote](https://github.com/quioteframework/quiote)'s database layer.

## Install

```
composer require quioteframework/db-cycle
```

## Enable

Add the plugin to your app's `plugins` config key:

```php
'plugins' => [\Quiote\Database\Adapter\Cycle\CyclePlugin::class],
```

Then reference it by alias in `databases.xml`:

```xml
<database name="default" class="cycle">
    ...
</database>
```

## License

MIT. See [LICENSE](LICENSE).
