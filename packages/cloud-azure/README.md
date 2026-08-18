# quioteframework/cloud-azure

Minimal Azure Storage clients for [Quiote](https://github.com/quioteframework/quiote).

- `Quiote\Storage\Azure\AzureBlobClient`: REST access to Blob Storage, Shared Key or Azure AD authenticated.
- `Quiote\Storage\Azure\AzureTableClient`: the same for Table Storage (Shared Key Lite only), which is a key/value store rather than object storage and is cheaper for small key/value-shaped payloads.

No `microsoft/azure-storage-*` dependency: these cover exactly the operations Quiote needs.

Bring your own PSR-18 HTTP client.

## Install

You normally do not install this directly: `quioteframework/session-azure` and `quioteframework/filesystem-azure` both depend on it.

```
composer require quioteframework/cloud-azure
```

## Use

`AzureBlobClient` takes an `AzureCredential` rather than a raw account key, so it never has to know how the request gets authorized:

```php
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureCredentialFactory;

$credential = AzureCredentialFactory::fromConfig(
    ['auth' => getenv('AZURE_STORAGE_AUTH') ?: 'shared_key', 'account_key' => getenv('AZURE_STORAGE_KEY') ?: ''],
    $psr18Client,
);

$blob = new AzureBlobClient(
    httpClient: $psr18Client,
    accountName: getenv('AZURE_STORAGE_ACCOUNT'),
    credential: $credential,
);
```

`auth` selects the strategy:

- `shared_key` (default): signs with `account_key`, the storage account's own key.
- `workload_identity`: exchanges the AKS workload identity webhook's projected service account token for a Storage-scoped Azure AD token. Reads `AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_FEDERATED_TOKEN_FILE` and `AZURE_AUTHORITY_HOST` straight from the environment; nothing to configure beyond `auth` itself.
- `cli`: reuses a developer's `az login` session by shelling out to `az account get-access-token`. For local development against a real storage account without ever handling an account key.
- `chain`: tries `workload_identity`, falling back to `cli` when the webhook variables are absent. The one strategy that works unmodified both in-cluster and on a laptop.

No account key is ever read for `workload_identity`, `cli` or `chain`.

`AzureBlobClient::listObjects()` lists blobs in a container (List Blobs), and `AzureBlobContainerClient::listObjects()` does the same bound to one container. Both normalize pagination, prefix/delimiter grouping and per-entry metadata the same way `S3Client` and `GcsClient` do; see `Quiote\Storage\ListableObjectStoreClientInterface`.

## License

MIT. See [LICENSE](LICENSE).
