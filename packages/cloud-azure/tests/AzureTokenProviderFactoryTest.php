<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureCliTokenProvider;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\AzureTokenProviderFactory;
use Quiote\Storage\Azure\ChainedTokenProvider;

final class AzureTokenProviderFactoryTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        putenv('AZURE_TENANT_ID');
        putenv('AZURE_CLIENT_ID');
        putenv('AZURE_FEDERATED_TOKEN_FILE');
    }

    public function testCliBuildsAnAzureCliTokenProvider(): void
    {
        $provider = AzureTokenProviderFactory::fromConfig(['auth' => 'cli'], new TokenProviderFactoryNeverCalledHttpClient());

        $this->assertInstanceOf(AzureCliTokenProvider::class, $provider);
    }

    public function testChainDoesNotThrowWhenWorkloadIdentityIsUnavailable(): void
    {
        $provider = AzureTokenProviderFactory::fromConfig(['auth' => 'chain'], new TokenProviderFactoryNeverCalledHttpClient());

        $this->assertInstanceOf(ChainedTokenProvider::class, $provider);
    }

    public function testWorkloadIdentityThrowsImmediatelyWithoutTheWebhookVariables(): void
    {
        $this->expectException(AzureStorageException::class);
        AzureTokenProviderFactory::fromConfig(['auth' => 'workload_identity'], new TokenProviderFactoryNeverCalledHttpClient());
    }

    public function testWorkloadIdentityDerivesTheScopeFromTheGivenResource(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'wi-token-');
        if ($tokenFile === false) {
            self::fail('Could not create a temporary federated token file.');
        }
        file_put_contents($tokenFile, "federated-token-contents\n");
        putenv('AZURE_TENANT_ID=tenant-1');
        putenv('AZURE_CLIENT_ID=client-1');
        putenv('AZURE_FEDERATED_TOKEN_FILE=' . $tokenFile);

        try {
            $http = new FactoryFakeAadTokenTransport();
            $provider = AzureTokenProviderFactory::fromConfig(['auth' => 'workload_identity'], $http, resource: 'https://api.loganalytics.io/');
            $provider->getToken();

            $body = (string) $http->requests[0]->getBody();
            $this->assertStringContainsString('scope=https%3A%2F%2Fapi.loganalytics.io%2F.default', $body);
        } finally {
            putenv('AZURE_TENANT_ID');
            putenv('AZURE_CLIENT_ID');
            putenv('AZURE_FEDERATED_TOKEN_FILE');
            @unlink($tokenFile);
        }
    }

    public function testSharedKeyIsRejectedSinceItIsNotABearerToken(): void
    {
        $this->expectException(AzureStorageException::class);
        AzureTokenProviderFactory::fromConfig(['auth' => 'shared_key'], new TokenProviderFactoryNeverCalledHttpClient());
    }

    public function testUnknownAuthStrategyThrows(): void
    {
        $this->expectException(AzureStorageException::class);
        AzureTokenProviderFactory::fromConfig(['auth' => 'nonsense'], new TokenProviderFactoryNeverCalledHttpClient());
    }
}

final class TokenProviderFactoryNeverCalledHttpClient implements ClientInterface
{
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        self::fail('No HTTP request should be sent while merely building a token provider.');
    }

    private static function fail(string $message): never
    {
        throw new \RuntimeException($message);
    }
}

final class FactoryFakeAadTokenTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response(200, [], json_encode(['access_token' => 'aad-token', 'expires_in' => 3600], JSON_THROW_ON_ERROR));
    }
}
