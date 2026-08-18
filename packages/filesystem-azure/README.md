# quioteframework/filesystem-azure

Azure Blob Storage filesystem adapter for [Quiote](https://github.com/quioteframework/quiote): a `Quiote\Filesystem\ListableFilesystemInterface` implementation for `Quiote\Filesystem\FilesystemManager`, built on the `AzureBlobClient` from `quioteframework/cloud-azure` (a hand-rolled REST client, not the official SDK).

`read()`/`write()`/`delete()`/`exists()`/`size()`/`lastModified()`/`listContents()` are all supported, `listContents()` pages List Blobs internally and returns one flat, sorted list.

## Install

```
composer require quioteframework/filesystem-azure
```

## Use

```php
$client = new \Quiote\Storage\Azure\AzureBlobClient(
    httpClient: $psr18Client,
    accountName: getenv('AZURE_STORAGE_ACCOUNT'),
    credential: new \Quiote\Storage\Azure\SharedKeyCredential(getenv('AZURE_STORAGE_KEY')),
);

$adapter = new \Quiote\Filesystem\Azure\AzureFilesystemAdapter($client, container: 'my-app-files');
$adapter->write('reports/q1.csv', $csv);
$adapter->read('reports/q1.csv');
$adapter->listContents('reports');
```

Or, via a container with `AzureFilesystemPlugin` registered and `filesystem.disks.azure.*` configured, resolve `Quiote\Filesystem\FilesystemManager` and call `->disk('azure')`. `filesystem.disks.azure.auth` (`shared_key`, `workload_identity`, `cli` or `chain`, see `quioteframework/cloud-azure`'s README) picks how requests are authorized; `account_key` is only read for `shared_key`.

## License

MIT. See [LICENSE](LICENSE).
