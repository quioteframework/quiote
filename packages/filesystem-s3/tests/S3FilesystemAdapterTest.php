<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Filesystem\S3\S3FilesystemAdapter;
use Quiote\Storage\S3\S3Client;

/**
 * Records requests and simulates just enough of the S3 REST surface (object
 * get/put/delete) for S3Client/S3FilesystemAdapter to be exercised without a
 * real AWS account. Mirrors packages/session-s3/tests/S3SessionPersistenceTest.php's
 * FakeS3Transport, plus a $failNextWith knob to simulate 5xx errors.
 */
final class FakeS3FilesystemTransport implements ClientInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public ?int $failNextWith = null;

    private Psr17Factory $psr17;

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->failNextWith !== null) {
            $status = $this->failNextWith;
            $this->failNextWith = null;
            return $this->psr17->createResponse($status)->withBody($this->psr17->createStream('boom'));
        }

        $path = $request->getUri()->getPath();

        return match ($request->getMethod()) {
            'PUT' => $this->handlePut($request, $path),
            'GET' => isset($this->objects[$path])
                ? $this->psr17->createResponse(200)->withBody($this->psr17->createStream($this->objects[$path]))
                : $this->psr17->createResponse(404),
            'DELETE' => $this->handleDelete($path),
            default => $this->psr17->createResponse(400),
        };
    }

    private function handlePut(RequestInterface $request, string $path): ResponseInterface
    {
        $this->objects[$path] = (string) $request->getBody();

        return $this->psr17->createResponse(200);
    }

    private function handleDelete(string $path): ResponseInterface
    {
        unset($this->objects[$path]);

        return $this->psr17->createResponse(204);
    }
}

final class S3FilesystemAdapterTest extends TestCase
{
    private FakeS3FilesystemTransport $transport;
    private S3FilesystemAdapter $adapter;

    #[\Override]
    protected function setUp(): void
    {
        $this->transport = new FakeS3FilesystemTransport();
        $client = new S3Client($this->transport, 'eu-west-1', 'AKIAFAKE', 'fake-secret', 'my-bucket');
        $this->adapter = new S3FilesystemAdapter($client, 'files/');
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $this->adapter->write('report.csv', 'a,b,c');

        $this->assertSame('a,b,c', $this->adapter->read('report.csv'));
    }

    public function testReadMissingFileThrowsFileNotFound(): void
    {
        $this->expectException(FileNotFoundStorageException::class);
        $this->adapter->read('missing.csv');
    }

    public function testReadServerErrorThrowsFilesystemStorageException(): void
    {
        $this->transport->failNextWith = 500;

        $this->expectException(FilesystemStorageException::class);
        $this->adapter->read('report.csv');
    }

    public function testWriteServerErrorThrowsFilesystemStorageException(): void
    {
        $this->transport->failNextWith = 500;

        $this->expectException(FilesystemStorageException::class);
        $this->adapter->write('report.csv', 'data');
    }

    public function testDeleteRemovesObject(): void
    {
        $this->adapter->write('report.csv', 'data');
        $this->adapter->delete('report.csv');

        $this->assertFalse($this->adapter->exists('report.csv'));
    }

    public function testDeleteOfMissingObjectDoesNotThrow(): void
    {
        $this->adapter->delete('never-existed.csv');
        $this->addToAssertionCount(1);
    }

    public function testDeleteServerErrorThrowsFilesystemStorageException(): void
    {
        $this->transport->failNextWith = 500;

        $this->expectException(FilesystemStorageException::class);
        $this->adapter->delete('report.csv');
    }

    public function testExistsTrueAndFalse(): void
    {
        $this->assertFalse($this->adapter->exists('report.csv'));

        $this->adapter->write('report.csv', 'data');

        $this->assertTrue($this->adapter->exists('report.csv'));
    }

    public function testObjectKeyIncludesBucketAndPrefix(): void
    {
        $this->adapter->write('report.csv', 'data');

        $this->assertArrayHasKey('/my-bucket/files/report.csv', $this->transport->objects);
    }

    public function testSizeIsNotSupported(): void
    {
        $this->expectException(FilesystemStorageException::class);
        $this->expectExceptionMessageMatches('/not supported/');
        $this->adapter->size('report.csv');
    }

    public function testLastModifiedIsNotSupported(): void
    {
        $this->expectException(FilesystemStorageException::class);
        $this->expectExceptionMessageMatches('/not supported/');
        $this->adapter->lastModified('report.csv');
    }

    public function testListContentsIsNotSupported(): void
    {
        $this->expectException(FilesystemStorageException::class);
        $this->expectExceptionMessageMatches('/not supported/');
        $this->adapter->listContents();
    }
}
