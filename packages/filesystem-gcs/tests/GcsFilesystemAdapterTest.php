<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Filesystem\Gcs\GcsFilesystemAdapter;
use Quiote\Storage\Gcs\GcsClient;

/** Mirrors packages/filesystem-s3/tests/S3FilesystemAdapterTest.php's fake transport. */
final class FakeGcsFilesystemTransport implements ClientInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public ?int $failNextWith = null;

    /** Simulates a server (or proxy) answering a HEAD without the metadata headers. */
    public bool $omitMetadataHeaders = false;

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
            'HEAD' => $this->handleHead($path),
            'DELETE' => $this->handleDelete($path),
            default => $this->psr17->createResponse(400),
        };
    }

    private function handleHead(string $path): ResponseInterface
    {
        if (!isset($this->objects[$path])) {
            return $this->psr17->createResponse(404);
        }
        if ($this->omitMetadataHeaders) {
            return $this->psr17->createResponse(200);
        }

        return $this->psr17->createResponse(200)
            ->withHeader('Content-Length', (string) strlen($this->objects[$path]))
            ->withHeader('Last-Modified', 'Wed, 21 Oct 2015 07:28:00 GMT')
            ->withHeader('ETag', '"' . md5($this->objects[$path]) . '"');
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

final class GcsFilesystemAdapterTest extends TestCase
{
    private FakeGcsFilesystemTransport $transport;
    private GcsFilesystemAdapter $adapter;

    #[\Override]
    protected function setUp(): void
    {
        $this->transport = new FakeGcsFilesystemTransport();
        $client = new GcsClient($this->transport, 'GOOGFAKE', 'fake-secret', 'my-bucket');
        $this->adapter = new GcsFilesystemAdapter($client, 'files/');
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

    public function testExistsUsesHeadRatherThanDownloadingTheBody(): void
    {
        $this->adapter->write('report.csv', 'data');
        $this->transport->requests = [];

        $this->adapter->exists('report.csv');

        $this->assertSame(['HEAD'], array_map(
            static fn (RequestInterface $request): string => $request->getMethod(),
            $this->transport->requests,
        ));
    }

    public function testSizeReturnsTheContentLength(): void
    {
        $this->adapter->write('report.csv', 'a,b,c');

        $this->assertSame(5, $this->adapter->size('report.csv'));
    }

    public function testSizeOfMissingFileThrowsFileNotFound(): void
    {
        $this->expectException(FileNotFoundStorageException::class);
        $this->adapter->size('missing.csv');
    }

    public function testSizeThrowsWhenTheResponseCarriesNoContentLength(): void
    {
        $this->adapter->write('report.csv', 'a,b,c');
        $this->transport->omitMetadataHeaders = true;

        $this->expectException(FilesystemStorageException::class);
        $this->expectExceptionMessageMatches('/Content-Length/');
        $this->adapter->size('report.csv');
    }

    public function testSizeServerErrorThrowsFilesystemStorageException(): void
    {
        $this->transport->failNextWith = 500;

        $this->expectException(FilesystemStorageException::class);
        $this->adapter->size('report.csv');
    }

    public function testLastModifiedReturnsTheParsedHeader(): void
    {
        $this->adapter->write('report.csv', 'a,b,c');

        $this->assertSame(
            '2015-10-21T07:28:00+00:00',
            $this->adapter->lastModified('report.csv')->format(DATE_ATOM),
        );
    }

    public function testLastModifiedOfMissingFileThrowsFileNotFound(): void
    {
        $this->expectException(FileNotFoundStorageException::class);
        $this->adapter->lastModified('missing.csv');
    }

    public function testLastModifiedThrowsWhenTheResponseCarriesNoTimestamp(): void
    {
        $this->adapter->write('report.csv', 'a,b,c');
        $this->transport->omitMetadataHeaders = true;

        $this->expectException(FilesystemStorageException::class);
        $this->expectExceptionMessageMatches('/Last-Modified/');
        $this->adapter->lastModified('report.csv');
    }

    public function testListContentsIsNotSupported(): void
    {
        $this->expectException(FilesystemStorageException::class);
        $this->expectExceptionMessageMatches('/not supported/');
        $this->adapter->listContents();
    }
}
