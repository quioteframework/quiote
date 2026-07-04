# quioteframework/db-eloquent

Eloquent (`illuminate/database`) driver adapter for [Quiote](https://github.com/quioteframework/quiote)'s database layer.

## Install

```
composer require quioteframework/db-eloquent
```

## Enable

Add the plugin to your app's `plugins` config key:

```php
'plugins' => [\Quiote\Database\Adapter\Eloquent\EloquentPlugin::class],
```

Then reference it by alias in `databases.xml`:

```xml
<database name="default" class="eloquent">
    ...
</database>
```

## License

MIT. See [LICENSE](LICENSE).
