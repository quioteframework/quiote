<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\AzureTokenProvider;
use Quiote\Storage\Azure\ChainedTokenProvider;

final class ChainedTokenProviderTest extends TestCase
{
    public function testLogsAtDebugWhenFallingThroughToTheNextProvider(): void
    {
        $logger = new RecordingLogger();
        $chain = new ChainedTokenProvider([
            $this->providerThrowing('workload identity unavailable'),
            $this->providerReturning('cli-token'),
        ], $logger);

        $chain->getToken();

        $this->assertCount(1, $logger->records);
        $this->assertSame('debug', $logger->records[0]['level']);
        $this->assertStringContainsString('workload identity unavailable', $logger->records[0]['message']);
    }

    public function testReturnsTheFirstTokenProduced(): void
    {
        $chain = new ChainedTokenProvider([
            $this->providerReturning('first-token'),
            $this->providerReturning('second-token'),
        ]);

        $this->assertSame('first-token', $chain->getToken());
    }

    public function testFallsThroughToTheNextProviderOnFailure(): void
    {
        $chain = new ChainedTokenProvider([
            $this->providerThrowing('workload identity unavailable'),
            $this->providerReturning('cli-token'),
        ]);

        $this->assertSame('cli-token', $chain->getToken());
    }

    public function testThrowsWithEveryFailureWhenAllProvidersFail(): void
    {
        $chain = new ChainedTokenProvider([
            $this->providerThrowing('workload identity unavailable'),
            $this->providerThrowing('az login required'),
        ]);

        try {
            $chain->getToken();
            self::fail('Expected an AzureStorageException.');
        } catch (AzureStorageException $e) {
            $this->assertStringContainsString('workload identity unavailable', $e->getMessage());
            $this->assertStringContainsString('az login required', $e->getMessage());
        }
    }

    private function providerReturning(string $token): AzureTokenProvider
    {
        return new class($token) implements AzureTokenProvider {
            public function __construct(private readonly string $token)
            {
            }

            #[\Override]
            public function getToken(): string
            {
                return $this->token;
            }
        };
    }

    private function providerThrowing(string $message): AzureTokenProvider
    {
        return new class($message) implements AzureTokenProvider {
            public function __construct(private readonly string $message)
            {
            }

            #[\Override]
            public function getToken(): string
            {
                throw new AzureStorageException($this->message);
            }
        };
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }
}
