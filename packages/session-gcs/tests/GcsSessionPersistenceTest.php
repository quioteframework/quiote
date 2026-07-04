<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Gcs\GcsClient;
use Quiote\Storage\Gcs\GcsSessionPersistence;

/**
 * Records requests and simulates just enough of the GCS XML API (object
 * get/put/delete) for GcsClient/GcsSessionPersistence to be exercised
 * without a real GCS bucket.
 */
final class FakeGcsTransport implements ClientInterface
{
    /** @var array<string, string> */
    public array $objects = [];

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

final class GcsSessionPersistenceTest extends TestCase
{
    private FakeGcsTransport $transport;
    private GcsSessionPersistence $persistence;

    #[\Override]
    protected function setUp(): void
    {
        $this->transport = new FakeGcsTransport();
        $client = new GcsClient($this->transport, 'GOOGFAKEACCESSKEY', 'fake-secret', 'my-bucket');
        $this->persistence = new GcsSessionPersistence($client, 'sessions/');
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

    public function testDeleteRemovesObject(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->delete('sid-1');

        $this->assertNull($this->persistence->load('sid-1'));
    }

    public function testObjectNameIncludesBucketAndPrefix(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);

        $this->assertArrayHasKey('/my-bucket/sessions/sid-1.json', $this->transport->objects);
    }

    public function testRequestsAreSigned(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);

        foreach ($this->transport->requests as $request) {
            $this->assertStringStartsWith('GOOG1 GOOGFAKEACCESSKEY:', $request->getHeaderLine('Authorization'));
        }
    }
}
