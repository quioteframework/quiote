<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureTableClient;
use Quiote\Storage\Azure\AzureTableSessionPersistence;

/**
 * Records requests and simulates just enough of the Table REST surface
 * (table create, entity get/upsert/delete) for AzureTableClient/
 * AzureTableSessionPersistence to be exercised without a real Azure account.
 */
final class FakeAzureTableTransport implements ClientInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $entities = [];

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
            'POST' => $this->psr17->createResponse(201),
            'PUT' => $this->handleUpsert($request, $path),
            'GET' => isset($this->entities[$path])
                ? $this->psr17->createResponse(200)->withBody($this->psr17->createStream(json_encode($this->entities[$path])))
                : $this->psr17->createResponse(404),
            'DELETE' => $this->handleDelete($path),
            default => $this->psr17->createResponse(400),
        };
    }

    private function handleUpsert(RequestInterface $request, string $path): ResponseInterface
    {
        $this->entities[$path] = json_decode((string) $request->getBody(), true);

        return $this->psr17->createResponse(204);
    }

    private function handleDelete(string $path): ResponseInterface
    {
        unset($this->entities[$path]);

        return $this->psr17->createResponse(204);
    }
}

final class AzureTableSessionPersistenceTest extends TestCase
{
    private FakeAzureTableTransport $transport;
    private AzureTableSessionPersistence $persistence;

    #[\Override]
    protected function setUp(): void
    {
        $this->transport = new FakeAzureTableTransport();
        $client = new AzureTableClient($this->transport, 'testaccount', base64_encode('fake-key-material'));
        $this->persistence = new AzureTableSessionPersistence($client, 'sessions');
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

    public function testSaveEnsuresTableOnce(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->save('sid-2', ['a' => 2]);

        $tableRequests = array_filter($this->transport->requests, static fn(RequestInterface $r): bool => $r->getMethod() === 'POST');
        $this->assertCount(1, $tableRequests);
    }

    public function testDeleteRemovesEntity(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->delete('sid-1');

        $this->assertNull($this->persistence->load('sid-1'));
    }

    public function testDeleteSendsIfMatchWildcard(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->delete('sid-1');

        $deleteRequests = array_values(array_filter($this->transport->requests, static fn(RequestInterface $r): bool => $r->getMethod() === 'DELETE'));
        $this->assertCount(1, $deleteRequests);
        $this->assertSame('*', $deleteRequests[0]->getHeaderLine('If-Match'));
    }

    public function testRequestsAreSigned(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);

        foreach ($this->transport->requests as $request) {
            $this->assertStringStartsWith('SharedKeyLite testaccount:', $request->getHeaderLine('Authorization'));
        }
    }
}
