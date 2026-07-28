# quioteframework/filesystem-azure

Azure Blob Storage filesystem adapter for [Quiote](https://github.com/quioteframework/quiote): a `Quiote\Filesystem\FilesystemAdapterInterface` implementation for `Quiote\Filesystem\FilesystemManager`, built on the `AzureBlobClient` from `quioteframework/session-azure` (Shared-Key REST client, not the official SDK).

`size()`, `lastModified()`, and `listContents()` are not supported in v1 — the underlying client has no blob-properties or list-blobs operation, so these throw `Quiote\Filesystem\FilesystemStorageException`. `read()`/`write()`/`delete()`/`exists()` are fully supported.

## Install

```
composer require quioteframework/filesystem-azure
```

## Use

```php
$client = new \Quiote\Storage\Azure\AzureBlobClient(
    httpClient: $psr18Client,
    accountName: getenv('AZURE_STORAGE_ACCOUNT'),
    accountKey: getenv('AZURE_STORAGE_KEY'),
);

$adapter = new \Quiote\Filesystem\Azure\AzureFilesystemAdapter($client, container: 'my-app-files');
$adapter->write('reports/q1.csv', $csv);
$adapter->read('reports/q1.csv');
```

Or, via a container with `AzureFilesystemPlugin` registered and `filesystem.disks.azure.*` configured, resolve `Quiote\Filesystem\FilesystemManager` and call `->disk('azure')`.

## License

MIT. See [LICENSE](LICENSE).
