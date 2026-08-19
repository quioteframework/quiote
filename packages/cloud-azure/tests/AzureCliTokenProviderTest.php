<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Storage\Azure\AzureCliProcessRunner;
use Quiote\Storage\Azure\AzureCliTokenProvider;
use Quiote\Storage\Azure\AzureStorageException;

final class AzureCliTokenProviderTest extends TestCase
{
    public function testGetTokenReturnsTheCliAccessToken(): void
    {
        $runner = new FakeAzureCliProcessRunner(json_encode(['accessToken' => 'cli-token'], JSON_THROW_ON_ERROR));
        $provider = new AzureCliTokenProvider($runner);

        $this->assertSame('cli-token', $provider->getToken());
        $this->assertSame(['az', 'account', 'get-access-token', '--resource', 'https://storage.azure.com/', '--output', 'json'], $runner->lastCommand);
    }

    public function testGetTokenCachesRatherThanShellingOutEveryCall(): void
    {
        $runner = new FakeAzureCliProcessRunner(json_encode(['accessToken' => 'cli-token'], JSON_THROW_ON_ERROR));
        $provider = new AzureCliTokenProvider($runner);

        $provider->getToken();
        $provider->getToken();

        $this->assertSame(1, $runner->calls);
    }

    public function testGetTokenPropagatesAProcessFailure(): void
    {
        $runner = new FakeAzureCliProcessRunner(null, new AzureStorageException('az: command not found'));
        $provider = new AzureCliTokenProvider($runner);

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testGetTokenThrowsOnUnparsableOutput(): void
    {
        $runner = new FakeAzureCliProcessRunner('not json');
        $provider = new AzureCliTokenProvider($runner);

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testGetTokenThrowsWhenNotLoggedIn(): void
    {
        $runner = new FakeAzureCliProcessRunner(json_encode(['error' => 'Please run az login'], JSON_THROW_ON_ERROR));
        $provider = new AzureCliTokenProvider($runner);

        $this->expectException(AzureStorageException::class);
        $provider->getToken();
    }

    public function testGetTokenRequestsATokenForACustomResourceWhenGiven(): void
    {
        $runner = new FakeAzureCliProcessRunner(json_encode(['accessToken' => 'log-analytics-token'], JSON_THROW_ON_ERROR));
        $provider = new AzureCliTokenProvider($runner, resource: 'https://api.loganalytics.io/');

        $this->assertSame('log-analytics-token', $provider->getToken());
        $this->assertSame(['az', 'account', 'get-access-token', '--resource', 'https://api.loganalytics.io/', '--output', 'json'], $runner->lastCommand);
    }
}

final class FakeAzureCliProcessRunner implements AzureCliProcessRunner
{
    public int $calls = 0;

    /** @var list<string> */
    public array $lastCommand = [];

    public function __construct(private readonly ?string $output, private readonly ?AzureStorageException $failure = null)
    {
    }

    /** @inheritDoc */
    #[\Override]
    public function run(array $command): string
    {
        $this->calls++;
        $this->lastCommand = $command;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->output ?? '';
    }
}
