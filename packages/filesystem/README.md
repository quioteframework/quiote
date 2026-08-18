# quioteframework/filesystem

[Quiote](https://github.com/quioteframework/quiote)'s filesystem subsystem: `Quiote\Filesystem\FilesystemAdapterInterface`/`ListableFilesystemInterface`, `FilesystemManager`, `FilesystemDriverRegistry`, `LocalFilesystemAdapter`, and the object-store-backed adapter base classes (`ObjectStoreFilesystemAdapter`, `ListableObjectStoreFilesystemAdapter`) that `quioteframework/filesystem-azure`, `-s3` and `-gcs` build their drivers on. Also ships `Quiote\Session\ObjectStoreSessionPersistence`, the equivalent shared base for `quioteframework/session-azure`, `-s3` and `-gcs`.

Opt-in like every Quiote plugin: even though `FilesystemPlugin` is what publishes `FilesystemManager` into the container, an app still lists it in `plugins` to get it. Split out of the framework core so it can release on its own schedule.

## Install

```
composer require quioteframework/filesystem
```

Then enable it:

```php
'plugins' => [
    \Quiote\Filesystem\FilesystemPlugin::class,
],
```

## Use

```php
$manager = $context->getContainer()->get(\Quiote\Filesystem\FilesystemManager::class);

$manager->disk('local')->write('reports/q1.csv', $csv);
$manager->disk()->read('reports/q1.csv'); // filesystem.default_disk
```

A cloud backend (`quioteframework/filesystem-azure`, `-s3`, `-gcs`) registers its own alias into `FilesystemDriverRegistry` from its own plugin; nothing here needs to change for that.

## License

MIT. See [LICENSE](LICENSE).
