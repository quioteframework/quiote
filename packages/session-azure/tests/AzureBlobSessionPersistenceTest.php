<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureBlobSessionPersistence;

/**
 * Records requests and simulates just enough of the Blob REST surface
 * (container create, blob get/put/delete) for AzureBlobClient/
 * AzureBlobSessionPersistence to be exercised without a real Azure account.
 */
final class FakeAzureBlobTransport implements ClientInterface
{
    /** @var array<string, string> */
    public array $blobs = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    private Psr17Factory $psr17;

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
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

final class AzureBlobSessionPersistenceTest extends TestCase
{
    private FakeAzureBlobTransport $transport;
    private AzureBlobSessionPersistence $persistence;

    #[\Override]
    protected function setUp(): void
    {
        $this->transport = new FakeAzureBlobTransport();
        $client = new AzureBlobClient($this->transport, 'testaccount', base64_encode('fake-key-material'));
        $this->persistence = new AzureBlobSessionPersistence($client, 'quiote-sessions');
    }

    public function testLoadUnknownSessionReturnsNull(): void
    {
        $this->assertNull($this->persistence->load('missing'));
    }

    public function testSaveThenLoadRoundTrips(): void
    {
        $this->persistence->save('sid-1', ['user_id' => 42]);

        $this->assertSame(['user_id' => 42], $this->persistence->load('sid-1'));
    }

    public function testSaveEnsuresContainerOnce(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->save('sid-2', ['a' => 2]);

        $containerRequests = array_filter(
            $this->transport->requests,
            static fn(RequestInterface $r): bool => str_contains($r->getUri()->getQuery(), 'restype=container'),
        );
        $this->assertCount(1, $containerRequests);
    }

    public function testDeleteRemovesBlob(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->delete('sid-1');

        $this->assertNull($this->persistence->load('sid-1'));
    }

    public function testRequestsAreSigned(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);

        foreach ($this->transport->requests as $request) {
            $this->assertStringStartsWith('SharedKey testaccount:', $request->getHeaderLine('Authorization'));
        }
    }
}
