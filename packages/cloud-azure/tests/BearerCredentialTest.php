<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\AzureTokenProvider;
use Quiote\Storage\Azure\BearerCredential;

final class BearerCredentialTest extends TestCase
{
    public function testAuthorizationHeaderWrapsTheProvidedToken(): void
    {
        $provider = new class implements AzureTokenProvider {
            #[\Override]
            public function getToken(): string
            {
                return 'the-token';
            }
        };

        $header = (new BearerCredential($provider))->authorizationHeader('myaccount', 'GET', '/sessions/abc.json', [], []);

        $this->assertSame('Bearer the-token', $header);
    }

    public function testPropagatesTheProvidersFailure(): void
    {
        $provider = new class implements AzureTokenProvider {
            #[\Override]
            public function getToken(): string
            {
                throw new AzureStorageException('no token available');
            }
        };

        $this->expectException(AzureStorageException::class);
        (new BearerCredential($provider))->authorizationHeader('myaccount', 'GET', '/sessions/abc.json', [], []);
    }
}
