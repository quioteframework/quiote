<?php

use PHPUnit\Framework\TestCase;
use Quiote\Http\Client\HttpClient;
use Quiote\Http\Client\HttpClientConfig;
use Quiote\Http\Client\HttpClientFactory;
use Quiote\Test\Http\Client\RecordingTransport;

/**
 * The dotnet-AddHttpClient-style named-client factory: named registration and,
 * crucially, memoization (one reused instance per name — the whole reason the
 * factory exists).
 */
class HttpClientFactoryTest extends TestCase
{
    public function testReturnsAnHttpClientForTheDefaultNameWithoutConfiguration(): void
    {
        $factory = new HttpClientFactory();
        $factory->setDefaultTransportFactory(fn() => new RecordingTransport());

        $this->assertInstanceOf(HttpClient::class, $factory->client());
    }

    public function testSameInstanceReturnedForSameName(): void
    {
        $factory = new HttpClientFactory();
        $factory->setDefaultTransportFactory(fn() => new RecordingTransport());
        $factory->configure('github', fn(HttpClientConfig $c) => $c->baseUri('https://api.github.com'));

        $this->assertSame($factory->client('github'), $factory->client('github'));
    }

    public function testDistinctInstancesForDistinctNames(): void
    {
        $factory = new HttpClientFactory();
        $factory->setDefaultTransportFactory(fn() => new RecordingTransport());
        $factory->configure('a', fn(HttpClientConfig $c) => $c->baseUri('https://a.example'));
        $factory->configure('b', fn(HttpClientConfig $c) => $c->baseUri('https://b.example'));

        $this->assertNotSame($factory->client('a'), $factory->client('b'));
    }

    public function testConfiguratorRunsOnceAndIsMemoized(): void
    {
        $factory = new HttpClientFactory();
        $factory->setDefaultTransportFactory(fn() => new RecordingTransport());
        $calls = 0;
        $factory->configure('svc', function (HttpClientConfig $c) use (&$calls): void {
            $calls++;
            $c->baseUri('https://svc.example');
        });

        $factory->client('svc');
        $factory->client('svc');

        $this->assertSame(1, $calls);
    }

    public function testConfiguredClientUsesItsBaseUri(): void
    {
        $factory = new HttpClientFactory();
        $transport = new RecordingTransport();
        $factory->configure('svc', fn(HttpClientConfig $c) => $c->baseUri('https://svc.example')->transport($transport));

        $factory->client('svc')->get('/ping');

        $this->assertSame('https://svc.example/ping', (string) $transport->lastRequest()->getUri());
    }

    public function testReconfiguringAfterBuildTakesEffectOnlyAfterReset(): void
    {
        $factory = new HttpClientFactory();
        $factory->setDefaultTransportFactory(fn() => new RecordingTransport());
        $factory->configure('svc', fn(HttpClientConfig $c) => $c->baseUri('https://one.example'));
        $first = $factory->client('svc');

        // configure() drops the memoized instance for that name, so a fresh build occurs.
        $factory->configure('svc', fn(HttpClientConfig $c) => $c->baseUri('https://two.example'));
        $second = $factory->client('svc');

        $this->assertNotSame($first, $second);
    }

    public function testHasReflectsConfiguredNamesAndDefault(): void
    {
        $factory = new HttpClientFactory();
        $this->assertTrue($factory->has(HttpClientFactory::DEFAULT));
        $this->assertFalse($factory->has('nope'));
        $factory->configure('nope', fn(HttpClientConfig $c) => null);
        $this->assertTrue($factory->has('nope'));
    }
}
