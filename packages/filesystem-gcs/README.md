# quioteframework/filesystem-gcs

Google Cloud Storage filesystem adapter for [Quiote](https://github.com/quioteframework/quiote): a `Quiote\Filesystem\FilesystemAdapterInterface` implementation for `Quiote\Filesystem\FilesystemManager`, built on the `GcsClient` from `quioteframework/session-gcs` (HMAC interoperability-key auth against the XML API, no `google/cloud-storage` dependency).

`size()`, `lastModified()`, and `listContents()` are not supported in v1 — the underlying client has no HEAD or list-bucket operation, so these throw `Quiote\Filesystem\FilesystemStorageException`. `read()`/`write()`/`delete()`/`exists()` are fully supported.

## Install

```
composer require quioteframework/filesystem-gcs
```

## Use

```php
$client = new \Quiote\Storage\Gcs\GcsClient(
    httpClient: $psr18Client,
    accessKey: getenv('GCS_HMAC_ACCESS_KEY'),
    secretKey: getenv('GCS_HMAC_SECRET_KEY'),
    bucket: 'my-app-files',
);

$adapter = new \Quiote\Filesystem\Gcs\GcsFilesystemAdapter($client);
$adapter->write('reports/q1.csv', $csv);
$adapter->read('reports/q1.csv');
```

Or, via a container with `GcsFilesystemPlugin` registered and `filesystem.disks.gcs.*` configured, resolve `Quiote\Filesystem\FilesystemManager` and call `->disk('gcs')`.

## License

MIT. See [LICENSE](LICENSE).
