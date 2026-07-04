# quioteframework/session-azure

Azure session backends for [Quiote](https://github.com/quioteframework/quiote): two `Quiote\Session\SessionPersistenceInterface` implementations for `Quiote\Session\SessionManager`, one on Blob Storage and one on Table Storage. Both are built on minimal hand-rolled REST clients, not an official Azure SDK — Microsoft stopped actively developing the PHP Blob SDK, and the handful of operations a session backend needs don't warrant the dependency weight. Bring your own PSR-18 HTTP client (e.g. Quiote's own `Quiote\Http\Client\HttpClient`, obtained via `HttpClientFactory`).

## Install

```
composer require quioteframework/session-azure
```

## Blob Storage: `AzureBlobSessionPersistence`

Stores one JSON blob per session id in a single container.

```php
$client = new \Quiote\Storage\Azure\AzureBlobClient(
    httpClient: $psr18Client,
    accountName: 'mystorageaccount',
    accountKey: getenv('AZURE_STORAGE_KEY'),
);

$manager = new \Quiote\Session\SessionManager(
    new \Quiote\Storage\Azure\AzureBlobSessionPersistence($client, container: 'quiote-sessions'),
);
```

Pass `endpoint` to `AzureBlobClient` to target Azurite or another Blob-compatible endpoint instead of `https://<account>.blob.core.windows.net`. The container is created on first `save()` (idempotent `PUT ?restype=container`, tolerant of a concurrent creation).

## Table Storage: `AzureTableSessionPersistence`

Cheaper than Blob Storage for small key/value-shaped session payloads, with no per-account container to manage — stores one entity per session id (partition key `session`, row key the session id) in a single table.

```php
$client = new \Quiote\Storage\Azure\AzureTableClient(
    httpClient: $psr18Client,
    accountName: 'mystorageaccount',
    accountKey: getenv('AZURE_STORAGE_KEY'),
);

$manager = new \Quiote\Session\SessionManager(
    new \Quiote\Storage\Azure\AzureTableSessionPersistence($client, table: 'sessions'),
);
```

The table is created on first `save()` (idempotent `POST /Tables`, tolerant of `TableAlreadyExists`). Authenticates with the Table service's "Shared Key Lite" scheme — distinct from Blob's "Shared Key" — but the same account name/key pair works for both.

## License

MIT. See [LICENSE](LICENSE).
