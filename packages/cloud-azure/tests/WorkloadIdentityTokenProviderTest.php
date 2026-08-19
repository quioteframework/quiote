<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\WorkloadIdentityTokenProvider;

final class WorkloadIdentityTokenProviderTest extends TestCase
{
    private string $tokenFile;

    #[\Override]
    protected function setUp(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'wi-token-');
        if ($tokenFile === false) {
            self::fail('Could not create a temporary federated token file.');
        }
        $this->tokenFile = $tokenFile;
        file_put_contents($this->tokenFile, "federated-token-contents\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->tokenFile);
    }

    public function testGetTokenExchangesTheFederatedTokenForAnAccessToken(): void
    {
        $http = new FakeAadTokenTransport(200, ['access_token' => 'aad-token', 'expires_in' => 3600]);
        $provider = new WorkloadIdentityTokenProvider($http, 'tenant-1', 'client-1', $this->tokenFile);

        $this->assertSame('aad-token', $provider->getToken());

        $request = $http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token', (string) $request->getUri());
        $body = (string) $request->getBody();
        $this->assertStringContainsString('client_assertion=federated-token-contents', $body);
        $this->assertStringContainsString('client_id=client-1', $body);
        $this->assertStringContainsString('scope=https%3A%2F%2Fstorage.azure.com%2F.default', $body);
    }

    public function testGetTokenCachesUntilNearExpiry(): void
    {
        $http = new FakeAadTokenTransport(200, ['access_token' => 'aad-token', 'expires_in' => 3600]);
        $provider = new WorkloadIdentityTokenProvider($http, 'tenant-1', 'client-1', $this->tokenFile);

        $provider->getToken();
        $provider->getToken();

        $this->assertCount(1, $http->requests);
    }

    public function testGetTokenThrowsOnANonSuccessResponse(): void
    {
        $http = new FakeAadTokenTransport(401, ['error' => 'invalid_client']);
        $provider = new WorkloadIdentityTokenProvider($http, 'tenant-1', 'client-1', $this->tokenFile);

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testGetTokenThrowsWhenTheResponseHasNoAccessToken(): void
    {
        $http = new FakeAadTokenTransport(200, ['token_type' => 'Bearer']);
        $provider = new WorkloadIdentityTokenProvider($http, 'tenant-1', 'client-1', $this->tokenFile);

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testGetTokenThrowsWhenTheFederatedTokenFileIsMissing(): void
    {
        $http = new FakeAadTokenTransport(200, ['access_token' => 'aad-token', 'expires_in' => 3600]);
        $provider = new WorkloadIdentityTokenProvider($http, 'tenant-1', 'client-1', '/nonexistent/path/token');

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testGetTokenRequestsACustomScopeWhenGiven(): void
    {
        $http = new FakeAadTokenTransport(200, ['access_token' => 'aad-token', 'expires_in' => 3600]);
        $provider = new WorkloadIdentityTokenProvider($http, 'tenant-1', 'client-1', $this->tokenFile, scope: 'https://api.loganalytics.io/.default');

        $provider->getToken();

        $body = (string) $http->requests[0]->getBody();
        $this->assertStringContainsString('scope=https%3A%2F%2Fapi.loganalytics.io%2F.default', $body);
    }

    public function testFromEnvironmentThrowsWhenTheWebhookVariablesAreAbsent(): void
    {
        $http = new FakeAadTokenTransport(200, ['access_token' => 'aad-token', 'expires_in' => 3600]);

        putenv('AZURE_TENANT_ID');
        putenv('AZURE_CLIENT_ID');
        putenv('AZURE_FEDERATED_TOKEN_FILE');

        $this->expectException(AzureStorageException::class);
        WorkloadIdentityTokenProvider::fromEnvironment($http);
    }

    public function testFromEnvironmentBuildsAProviderWhenTheWebhookVariablesArePresent(): void
    {
        $http = new FakeAadTokenTransport(200, ['access_token' => 'aad-token', 'expires_in' => 3600]);

        putenv('AZURE_TENANT_ID=tenant-1');
        putenv('AZURE_CLIENT_ID=client-1');
        putenv('AZURE_FEDERATED_TOKEN_FILE=' . $this->tokenFile);

        try {
            $provider = WorkloadIdentityTokenProvider::fromEnvironment($http);
            $this->assertSame('aad-token', $provider->getToken());
        } finally {
            putenv('AZURE_TENANT_ID');
            putenv('AZURE_CLIENT_ID');
            putenv('AZURE_FEDERATED_TOKEN_FILE');
        }
    }
}

final class FakeAadTokenTransport implements ClientInterface
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
