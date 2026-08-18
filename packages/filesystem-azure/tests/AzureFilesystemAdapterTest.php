<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Filesystem\Azure\AzureFilesystemAdapter;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemAdapterInterface;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Filesystem\ListableFilesystemInterface;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\SharedKeyCredential;

/** Mirrors packages/filesystem-s3/tests/S3FilesystemAdapterTest.php's fake transport. */
final class FakeAzureFilesystemTransport implements ClientInterface
{
    /** @var array<string, string> */
    public array $blobs = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** Persists across all of AzureBlobClient's internal retry attempts (unlike a one-shot flag). */
    public ?int $failWith = null;

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

        if ($this->failWith !== null) {
            return $this->psr17->createResponse($this->failWith)->withBody($this->psr17->createStream('boom'));
        }

        $path = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();

        return match ($request->getMethod()) {
            'PUT' => $this->handlePut($request, $path),
            'GET' => $path === '/my-container' && str_contains($query, 'comp=list')
                ? $this->handleList($query)
                : (isset($this->blobs[$path])
                    ? $this->psr17->createResponse(200)->withBody($this->psr17->createStream($this->blobs[$path]))
                    : $this->psr17->createResponse(404)),
            'HEAD' => $this->handleHead($path),
            'DELETE' => $this->handleDelete($path),
            default => $this->psr17->createResponse(400),
        };
    }

    /**
     * A single-page fake of List Blobs: real pagination is exercised at the AzureBlobClient
     * level, this only needs prefix/delimiter grouping for the filesystem adapter's
     * listContents().
     */
    private function handleList(string $query): ResponseInterface
    {
        parse_str($query, $params);
        $prefix = is_string($params['prefix'] ?? null) ? $params['prefix'] : '';
        $delimiter = is_string($params['delimiter'] ?? null) ? $params['delimiter'] : '';

        $names = [];
        foreach (array_keys($this->blobs) as $path) {
            $name = substr($path, strlen('/my-container/'));
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }
            $names[] = $name;
        }
        sort($names);

        $blobsXml = '';
        $prefixesXml = '';
        $seenPrefixes = [];
        foreach ($names as $name) {
            $rest = substr($name, strlen($prefix));
            $delimiterPos = $delimiter === '' ? false : strpos($rest, $delimiter);
            if ($delimiterPos !== false) {
                $commonPrefix = $prefix . substr($rest, 0, $delimiterPos + 1);
                if (!isset($seenPrefixes[$commonPrefix])) {
                    $seenPrefixes[$commonPrefix] = true;
                    $prefixesXml .= '<BlobPrefix><Name>' . htmlspecialchars($commonPrefix) . '</Name></BlobPrefix>';
                }
                continue;
            }
            $body = $this->blobs['/my-container/' . $name];
            $blobsXml .= sprintf(
                '<Blob><Name>%s</Name><Properties><Last-Modified>Wed, 21 Oct 2015 07:28:00 GMT</Last-Modified><Etag>"%s"</Etag><Content-Length>%d</Content-Length></Properties></Blob>',
                htmlspecialchars($name),
                md5($body),
                strlen($body),
            );
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><EnumerationResults><Blobs>{$blobsXml}{$prefixesXml}</Blobs><NextMarker/></EnumerationResults>";

        return $this->psr17->createResponse(200)->withBody($this->psr17->createStream($xml));
    }

    private function handleHead(string $path): ResponseInterface
    {
        if (!isset($this->blobs[$path])) {
            return $this->psr17->createResponse(404);
        }
        if ($this->omitMetadataHeaders) {
            return $this->psr17->createResponse(200);
        }

        return $this->psr17->createResponse(200)
            ->withHeader('Content-Length', (string) strlen($this->blobs[$path]))
            ->withHeader('Last-Modified', 'Wed, 21 Oct 2015 07:28:00 GMT')
            ->withHeader('ETag', '"' . md5($this->blobs[$path]) . '"');
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
        $client = new AzureBlobClient($this->transport, 'myaccount', new SharedKeyCredential(base64_encode('fake-key')));
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
        $this->transport->failWith = 500;

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

    public function testAdapterIsListable(): void
    {
        $this->assertInstanceOf(FilesystemAdapterInterface::class, $this->adapter);
        $this->assertInstanceOf(ListableFilesystemInterface::class, $this->adapter);
    }

    public function testListContentsReturnsFilesUnderThePrefix(): void
    {
        $this->adapter->write('reports/q1.csv', 'a');
        $this->adapter->write('reports/q2.csv', 'b');
        $this->adapter->write('other.csv', 'c');

        $this->assertSame(['q1.csv', 'q2.csv'], $this->adapter->listContents('reports'));
    }

    public function testListContentsGroupsDeeperKeysAsASingleEntry(): void
    {
        $this->adapter->write('reports/2024/q1.csv', 'a');
        $this->adapter->write('reports/summary.csv', 'b');

        $this->assertSame(['2024', 'summary.csv'], $this->adapter->listContents('reports'));
    }

    public function testListContentsOfAnEmptyPrefixIsEmpty(): void
    {
        $this->assertSame([], $this->adapter->listContents('missing'));
    }

    public function testListContentsServerErrorThrowsFilesystemStorageException(): void
    {
        $this->transport->failWith = 500;

        $this->expectException(FilesystemStorageException::class);
        $this->adapter->listContents();
    }
}
