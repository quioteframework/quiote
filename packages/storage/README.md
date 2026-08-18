# quioteframework/storage

The object-store contracts shared by [Quiote](https://github.com/quioteframework/quiote)'s cloud storage clients: `Quiote\Storage\ObjectStoreClientInterface`, `ListableObjectStoreClientInterface`, `ObjectMetadata`, `ObjectListing`, `ObjectSummary` and `ObjectStoreException`.

No dependency on `quioteframework/quiote`, just `psr/http-message`. `quioteframework/cloud-azure`, `quioteframework/cloud-s3` and `quioteframework/cloud-gcs` build their REST clients against these types, so any of them is usable standalone, in any PHP project, not only a Quiote application.

## Install

You normally do not install this directly, it comes in transitively with `quioteframework/cloud-azure`, `quioteframework/cloud-s3` or `quioteframework/cloud-gcs`.

```
composer require quioteframework/storage
```

## Use

```php
final class MyObjectStoreConsumer
{
    public function __construct(private readonly \Quiote\Storage\ObjectStoreClientInterface $client)
    {
    }

    public function read(string $key): ?string
    {
        return $this->client->get($key);
    }
}

// Works with any client built against the interface:
$consumer = new MyObjectStoreConsumer(new \Quiote\Storage\Azure\AzureBlobContainerClient($blobClient, 'my-container'));
```

`ListableObjectStoreClientInterface` extends the base contract with `listObjects()`, normalizing pagination (an opaque continuation token), prefix/delimiter grouping and per-entry metadata (size, ETag, last-modified) across providers whose own REST APIs each shape listing differently.

## License

MIT. See [LICENSE](LICENSE).
