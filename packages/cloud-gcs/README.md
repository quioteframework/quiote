# quioteframework/cloud-gcs

Minimal Google Cloud Storage client for [Quiote](https://github.com/quioteframework/quiote).

`Quiote\Storage\Gcs\GcsClient` talks to GCS through its S3-compatible interoperability API, so credentials are an HMAC key pair rather than a service-account JSON file, and no Google SDK is needed for `get`/`put`/`delete` on a single bucket.

Bring your own PSR-18 HTTP client.

## Install

You normally do not install this directly — `quioteframework/session-gcs` and `quioteframework/filesystem-gcs` both depend on it.

```
composer require quioteframework/cloud-gcs
```

## Use

```php
$client = new \Quiote\Storage\Gcs\GcsClient(
    httpClient: $psr18Client,
    accessKey: getenv('GCS_HMAC_ACCESS_KEY'),
    secretKey: getenv('GCS_HMAC_SECRET'),
    bucket: 'my-bucket',
);
```

## License

MIT. See [LICENSE](LICENSE).
