# quioteframework/db-doctrine

Doctrine ORM and DBAL driver adapters for [Quiote](https://github.com/quioteframework/quiote)'s database layer.

## Install

```
composer require quioteframework/db-doctrine
```

## Enable

Add the plugin to your app's `plugins` config key:

```php
'plugins' => [\Quiote\Database\Adapter\Doctrine\DoctrinePlugin::class],
```

Then reference it by alias in `databases.xml` — `doctrine` for the full ORM, `doctrine_dbal` for the query builder without the ORM:

```xml
<database name="default" class="doctrine">
    ...
</database>
```

## License

MIT. See [LICENSE](LICENSE).
