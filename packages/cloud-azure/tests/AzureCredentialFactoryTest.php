<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\Azure\AzureCredentialFactory;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\BearerCredential;
use Quiote\Storage\Azure\SharedKeyCredential;

final class AzureCredentialFactoryTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        putenv('AZURE_TENANT_ID');
        putenv('AZURE_CLIENT_ID');
        putenv('AZURE_FEDERATED_TOKEN_FILE');
    }

    public function testDefaultsToSharedKey(): void
    {
        $credential = AzureCredentialFactory::fromConfig(['account_key' => base64_encode('key')], new NeverCalledHttpClient());

        $this->assertInstanceOf(SharedKeyCredential::class, $credential);
    }

    public function testCliBuildsABearerCredential(): void
    {
        $credential = AzureCredentialFactory::fromConfig(['auth' => 'cli'], new NeverCalledHttpClient());

        $this->assertInstanceOf(BearerCredential::class, $credential);
    }

    public function testChainDoesNotThrowWhenWorkloadIdentityIsUnavailable(): void
    {
        $credential = AzureCredentialFactory::fromConfig(['auth' => 'chain'], new NeverCalledHttpClient());

        $this->assertInstanceOf(BearerCredential::class, $credential);
    }

    public function testWorkloadIdentityThrowsImmediatelyWhenRequestedExplicitlyWithoutTheWebhookVariables(): void
    {
        $this->expectException(AzureStorageException::class);
        AzureCredentialFactory::fromConfig(['auth' => 'workload_identity'], new NeverCalledHttpClient());
    }

    public function testUnknownAuthStrategyThrows(): void
    {
        $this->expectException(AzureStorageException::class);
        AzureCredentialFactory::fromConfig(['auth' => 'nonsense'], new NeverCalledHttpClient());
    }
}

final class NeverCalledHttpClient implements ClientInterface
{
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        self::fail('No HTTP request should be sent while merely building a credential.');
    }

    private static function fail(string $message): never
    {
        throw new \RuntimeException($message);
    }
}
