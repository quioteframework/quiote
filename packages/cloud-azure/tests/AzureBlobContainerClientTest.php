<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureBlobContainerClient;
use Quiote\Storage\Azure\SharedKeyCredential;
use Quiote\Storage\ListableObjectStoreClientInterface;

final class AzureBlobContainerClientTest extends TestCase
{
    public function testImplementsTheListableInterface(): void
    {
        $container = new AzureBlobContainerClient(
            new AzureBlobClient(new RecordingTransport(), 'myaccount', new SharedKeyCredential(base64_encode('key'))),
            'my-container',
        );

        $this->assertInstanceOf(ListableObjectStoreClientInterface::class, $container);
    }

    public function testListObjectsDelegatesToTheClientWithTheBoundContainer(): void
    {
        $transport = new RecordingTransport(200, '<EnumerationResults><Blobs></Blobs><NextMarker/></EnumerationResults>');
        $container = new AzureBlobContainerClient(
            new AzureBlobClient($transport, 'myaccount', new SharedKeyCredential(base64_encode('key'))),
            'my-container',
        );

        $listing = $container->listObjects('reports/', '/');

        $this->assertSame([], $listing->objects);
        $request = $transport->sent[0];
        $this->assertSame('/my-container', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('reports/', $query['prefix']);
        $this->assertSame('/', $query['delimiter']);
    }
}

final class RecordingTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    public function __construct(private readonly int $status = 200, private readonly string $body = '')
    {
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        return new Response($this->status, [], $this->body);
    }
}
