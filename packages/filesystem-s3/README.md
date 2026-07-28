# quioteframework/filesystem-s3

AWS S3 filesystem adapter for [Quiote](https://github.com/quioteframework/quiote): a `Quiote\Filesystem\FilesystemAdapterInterface` implementation for `Quiote\Filesystem\FilesystemManager`, built on the `S3Client` from `quioteframework/session-s3` (a minimal hand-rolled AWS Signature Version 4 REST client, not `aws/aws-sdk-php`).

`size()`, `lastModified()`, and `listContents()` are not supported in v1 — the underlying client has no HEAD or list-bucket operation, so these throw `Quiote\Filesystem\FilesystemStorageException`. `read()`/`write()`/`delete()`/`exists()` are fully supported.

## Install

```
composer require quioteframework/filesystem-s3
```

## Use

```php
$client = new \Quiote\Storage\S3\S3Client(
    httpClient: $psr18Client,
    region: 'eu-west-1',
    accessKeyId: getenv('AWS_ACCESS_KEY_ID'),
    secretAccessKey: getenv('AWS_SECRET_ACCESS_KEY'),
    bucket: 'my-app-files',
);

$adapter = new \Quiote\Filesystem\S3\S3FilesystemAdapter($client);
$adapter->write('reports/q1.csv', $csv);
$adapter->read('reports/q1.csv');
```

Or, via a container with `S3FilesystemPlugin` registered and `filesystem.disks.s3.*` configured, resolve `Quiote\Filesystem\FilesystemManager` and call `->disk('s3')`.

## License

MIT. See [LICENSE](LICENSE).
