# quioteframework/cloud-s3

Minimal AWS S3 REST client for [Quiote](https://github.com/quioteframework/quiote).

`Quiote\Storage\S3\S3Client` signs requests with AWS Signature Version 4 and exposes just `get`/`put`/`delete` on a single bucket. Deliberately not `aws/aws-sdk-php`: that SDK bundles a client for every AWS service, and the things Quiote stores in S3 — sessions, uploaded files — only ever need three operations on one object.

Path-style requests, so `endpoint` also points at any S3-compatible service (MinIO, Ceph, R2).

Bring your own PSR-18 HTTP client.

## Install

You normally do not install this directly — `quioteframework/session-s3` and `quioteframework/filesystem-s3` both depend on it, and either will pull it in.

```
composer require quioteframework/cloud-s3
```

## Use

```php
$client = new \Quiote\Storage\S3\S3Client(
    httpClient: $psr18Client,
    region: 'eu-west-1',
    accessKeyId: getenv('AWS_ACCESS_KEY_ID'),
    secretAccessKey: getenv('AWS_SECRET_ACCESS_KEY'),
    bucket: 'my-bucket',
);
```

The bucket must already exist; creation and lifecycle belong to infrastructure tooling.

## License

MIT. See [LICENSE](LICENSE).
