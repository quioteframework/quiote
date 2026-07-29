# quioteframework/cloud-azure

Minimal Azure Storage clients for [Quiote](https://github.com/quioteframework/quiote).

- `Quiote\Storage\Azure\AzureBlobClient` — SharedKey-signed REST access to Blob Storage.
- `Quiote\Storage\Azure\AzureTableClient` — the same for Table Storage, which is a key/value store rather than object storage and is cheaper for small key/value-shaped payloads.

No `microsoft/azure-storage-*` dependency: these cover exactly the operations Quiote needs.

Bring your own PSR-18 HTTP client.

## Install

You normally do not install this directly — `quioteframework/session-azure` and `quioteframework/filesystem-azure` both depend on it.

```
composer require quioteframework/cloud-azure
```

## Use

```php
$blob = new \Quiote\Storage\Azure\AzureBlobClient(
    httpClient: $psr18Client,
    accountName: getenv('AZURE_STORAGE_ACCOUNT'),
    accountKey: getenv('AZURE_STORAGE_KEY'),
);
```

## License

MIT. See [LICENSE](LICENSE).
