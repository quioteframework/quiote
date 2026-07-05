# quioteframework/db-propulsion

Propulsion (`quioteframework/propulsion`) driver adapter for [Quiote](https://github.com/quioteframework/quiote)'s database layer.

## Install

```
composer require quioteframework/db-propulsion quioteframework/propulsion
```

## Enable

Add the plugin to your app's `plugins` config key:

```php
'plugins' => [\Quiote\Database\Adapter\Propulsion\PropulsionPlugin::class],
```

Then reference it by alias in `databases.xml`:

```xml
<database name="default" class="propulsion">
    ...
</database>
```

## License

MIT. See [LICENSE](LICENSE).
