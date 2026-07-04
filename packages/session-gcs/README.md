# quioteframework/session-gcs

Google Cloud Storage session backend for [Quiote](https://github.com/quioteframework/quiote): a `Quiote\Session\SessionPersistenceInterface` implementation for `Quiote\Session\SessionManager`, storing one JSON object per session id in a bucket.

Authenticates with a GCS **HMAC key pair** (`Settings > Interoperability` in the Cloud Console, or `gcloud storage hmac create`) against the XML API — GCS's own S3-compatible auth mode, meant for exactly this kind of tool — instead of a full service-account OAuth2/JWT flow or the `google/cloud-storage` SDK. If you need IAM-scoped service-account credentials instead of a standalone HMAC key pair, use that SDK instead; this package trades that for a much smaller footprint.

## Install

```
composer require quioteframework/session-gcs
```

## Use

```php
$client = new \Quiote\Storage\Gcs\GcsClient(
    httpClient: $psr18Client,
    accessKey: getenv('GCS_HMAC_ACCESS_KEY'),
    secretKey: getenv('GCS_HMAC_SECRET'),
    bucket: 'my-app-sessions',
);

$manager = new \Quiote\Session\SessionManager(
    new \Quiote\Storage\Gcs\GcsSessionPersistence($client, objectPrefix: 'sessions/'),
);
```

The bucket must already exist.

## License

MIT. See [LICENSE](LICENSE).
