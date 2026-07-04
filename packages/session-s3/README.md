# quioteframework/session-s3

AWS S3 session backend for [Quiote](https://github.com/quioteframework/quiote): a `Quiote\Session\SessionPersistenceInterface` implementation for `Quiote\Session\SessionManager`, storing one JSON object per session id in a bucket.

Built on a minimal hand-rolled AWS Signature Version 4 REST client, not `aws/aws-sdk-php` — that SDK bundles a client for every AWS service; a session backend only ever needs get/put/delete on a single object. Bring your own PSR-18 HTTP client. Path-style requests, so `endpoint` also works against any S3-compatible service (MinIO, etc).

## Install

```
composer require quioteframework/session-s3
```

## Use

```php
$client = new \Quiote\Storage\S3\S3Client(
    httpClient: $psr18Client,
    region: 'eu-west-1',
    accessKeyId: getenv('AWS_ACCESS_KEY_ID'),
    secretAccessKey: getenv('AWS_SECRET_ACCESS_KEY'),
    bucket: 'my-app-sessions',
);

$manager = new \Quiote\Session\SessionManager(
    new \Quiote\Storage\S3\S3SessionPersistence($client, keyPrefix: 'sessions/'),
);
```

The bucket must already exist — bucket creation/lifecycle is left to infrastructure tooling, not this package.

## License

MIT. See [LICENSE](LICENSE).
