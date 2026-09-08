<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\ManagedIdentityTokenProvider;

final class ManagedIdentityTokenProviderTest extends TestCase
{
    public function testGetTokenRequestsATokenFromImds(): void
    {
        $http = new FakeImdsTransport(200, ['access_token' => 'imds-token', 'expires_in' => '3600']);
        $provider = new ManagedIdentityTokenProvider($http);

        $this->assertSame('imds-token', $provider->getToken());

        $request = $http->requests[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('true', $request->getHeaderLine('Metadata'));
        $this->assertStringStartsWith('http://169.254.169.254/metadata/identity/oauth2/token', (string) $request->getUri());
        $this->assertStringContainsString('api-version=2018-02-01', (string) $request->getUri());
        $this->assertStringContainsString('resource=https%3A%2F%2Fstorage.azure.com%2F', (string) $request->getUri());
    }

    public function testGetTokenIncludesTheClientIdForAUserAssignedIdentity(): void
    {
        $http = new FakeImdsTransport(200, ['access_token' => 'imds-token', 'expires_in' => '3600']);
        $provider = new ManagedIdentityTokenProvider($http, clientId: 'user-assigned-1');

        $provider->getToken();

        $this->assertStringContainsString('client_id=user-assigned-1', (string) $http->requests[0]->getUri());
    }

    public function testGetTokenCachesUntilNearExpiry(): void
    {
        $http = new FakeImdsTransport(200, ['access_token' => 'imds-token', 'expires_in' => '3600']);
        $provider = new ManagedIdentityTokenProvider($http);

        $provider->getToken();
        $provider->getToken();

        $this->assertCount(1, $http->requests);
    }

    public function testGetTokenThrowsOnANonSuccessResponse(): void
    {
        $http = new FakeImdsTransport(400, ['error' => 'invalid_request']);
        $provider = new ManagedIdentityTokenProvider($http);

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testGetTokenThrowsWhenTheResponseHasNoAccessToken(): void
    {
        $http = new FakeImdsTransport(200, ['token_type' => 'Bearer']);
        $provider = new ManagedIdentityTokenProvider($http);

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testGetTokenThrowsWhenTheResponseIsNotValidJson(): void
    {
        $http = new RawBodyFakeImdsTransport(200, 'not json');
        $provider = new ManagedIdentityTokenProvider($http);

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testGetTokenThrowsWhenImdsIsUnreachable(): void
    {
        $http = new ThrowingImdsTransport();
        $provider = new ManagedIdentityTokenProvider($http);

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testIsReachableReturnsTrueWhenSomethingIsListening(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if ($server === false) {
            self::fail('Could not start a local TCP listener for the reachability probe test.');
        }
        $address = stream_socket_get_name($server, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        try {
            $this->assertTrue(ManagedIdentityTokenProvider::isReachable(host: '127.0.0.1', port: $port));
        } finally {
            fclose($server);
        }
    }

    public function testIsReachableReturnsFalseWhenNothingIsListening(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if ($server === false) {
            self::fail('Could not reserve a local port for the reachability probe test.');
        }
        $address = stream_socket_get_name($server, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($server);

        $this->assertFalse(ManagedIdentityTokenProvider::isReachable(host: '127.0.0.1', port: $port));
    }
}

final class FakeImdsTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param array<string, mixed> $body */
    public function __construct(private readonly int $status, private readonly array $body)
    {
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response($this->status, [], json_encode($this->body, JSON_THROW_ON_ERROR));
    }
}

final class RawBodyFakeImdsTransport implements ClientInterface
{
    public function __construct(private readonly int $status, private readonly string $body)
    {
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response($this->status, [], $this->body);
    }
}

final class ThrowingImdsTransport implements ClientInterface
{
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new class extends \RuntimeException implements ClientExceptionInterface {
        };
    }
}
