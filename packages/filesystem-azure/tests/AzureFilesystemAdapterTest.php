<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Filesystem\Azure\AzureFilesystemAdapter;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Storage\Azure\AzureBlobClient;

/** Mirrors packages/filesystem-s3/tests/S3FilesystemAdapterTest.php's fake transport. */
final class FakeAzureFilesystemTransport implements ClientInterface
{
    /** @var array<string, string> */
    public array $blobs = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** Persists across all of AzureBlobClient's internal retry attempts (unlike a one-shot flag). */
    public ?int $failWith = null;

    private Psr17Factory $psr17;

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->failWith !== null) {
            return $this->psr17->createResponse($this->failWith)->withBody($this->psr17->createStream('boom'));
        }

        $path = $request->getUri()->getPath();

        return match ($request->getMethod()) {
            'PUT' => $this->handlePut($request, $path),
            'GET' => isset($this->blobs[$path])
                ? $this->psr17->createResponse(200)->withBody($this->psr17->createStream($this->blobs[$path]))
                : $this->psr17->createResponse(404),
            'DELETE' => $this->handleDelete($path),
            default => $this->psr17->createResponse(400),
        };
    }

    private function handlePut(RequestInterface $request, string $path): ResponseInterface
    {
        if (str_contains($request->getUri()->getQuery(), 'restype=container')) {
            return $this->psr17->createResponse(201);
        }
        $this->blobs[$path] = (string) $request->getBody();

        return $this->psr17->createResponse(201);
    }

    private function handleDelete(string $path): ResponseInterface
    {
        unset($this->blobs[$path]);

        return $this->psr17->createResponse(202);
    }
}

final class AzureFilesystemAdapterTest extends TestCase
{
    private FakeAzureFilesystemTransport $transport;
    private AzureFilesystemAdapter $adapter;

    #[\Override]
    protected function setUp(): void
    {
        $this->transport = new FakeAzureFilesystemTransport();
        $client = new AzureBlobClient($this->transport, 'myaccount', base64_encode('fake-key'));
        $this->adapter = new AzureFilesystemAdapter($client, 'my-container', 'files/');
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
        $this->transport->failWith = 500;

        $this->expectException(FilesystemStorageException::class);
        $this->adapter->read('report.csv');
    }

    public function testWriteServerErrorThrowsFilesystemStorageException(): void
    {
        $this->transport->failWith = 500;

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
        $this->transport->failWith = 500;

        $this->expectException(FilesystemStorageException::class);
        $this->adapter->delete('report.csv');
    }

    public function testExistsTrueAndFalse(): void
    {
        $this->assertFalse($this->adapter->exists('report.csv'));

        $this->adapter->write('report.csv', 'data');

        $this->assertTrue($this->adapter->exists('report.csv'));
    }

    public function testObjectKeyIncludesContainerAndPrefix(): void
    {
        $this->adapter->write('report.csv', 'data');

        $this->assertArrayHasKey('/my-container/files/report.csv', $this->transport->blobs);
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
