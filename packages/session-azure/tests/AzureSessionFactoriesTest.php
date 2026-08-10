<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Session\SessionPersistenceInterface;
use Quiote\Storage\Azure\AzureBlobSessionFactory;
use Quiote\Storage\Azure\AzureBlobSessionPersistence;
use Quiote\Storage\Azure\AzureSessionParameters;
use Quiote\Storage\Azure\AzureTableSessionFactory;
use Quiote\Storage\Azure\AzureTableSessionPersistence;

/**
 * The two `session` slot factories: an application must be able to name
 * either class in factories config and get a working backend, with no
 * hand-written wrapper. Where the built backend actually points is only
 * observable from the requests it issues, so each test drives it through one
 * operation and reads the recorded request.
 */
final class AzureSessionFactoriesTest extends TestCase
{
    /**
     * Answers 404 to everything -- enough for a load() of a session that was
     * never stored -- while keeping every request for inspection.
     */
    private function recordingClient(): ClientInterface
    {
        return new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return (new Psr17Factory())->createResponse(404);
            }
        };
    }

    private function context(?ClientInterface $client): Context
    {
        $context = new class ('test') extends Context {
            public ?ClientInterface $client = null;

            public function __construct(string $name)
            {
                parent::__construct($name);
            }

            #[\Override]
            public function getContainer(): Container
            {
                $container = new Container();
                if ($this->client !== null) {
                    $container->setFactory(ClientInterface::class, fn() => $this->client);
                }

                return $container;
            }
        };
        $context->client = $client;

        return $context;
    }

    /** The URI of the first request the built backend issued. */
    private function firstRequestUri(ClientInterface $client, SessionPersistenceInterface $persistence): string
    {
        $persistence->load('sid-1');

        /** @var list<RequestInterface> $requests */
        $requests = $client->requests; // @phpstan-ignore property.notFound
        $this->assertNotEmpty($requests, 'the backend issued no request at all');

        return (string) $requests[0]->getUri();
    }

    // -- blob --------------------------------------------------------------

    public function testTheBlobFactoryBuildsABlobBackedPersistence(): void
    {
        $persistence = (new AzureBlobSessionFactory())->createPersistence($this->context($this->recordingClient()), [
            'account_name' => 'testaccount',
            'account_key' => base64_encode('fake-key-material'),
            'container' => 'my-sessions',
        ]);

        $this->assertInstanceOf(AzureBlobSessionPersistence::class, $persistence);
        $this->assertNull($persistence->load('never-stored'), 'a 404 means "no such session", not an error');
    }

    public function testTheBlobFactoryPointsAtTheConfiguredAccountAndContainer(): void
    {
        $client = $this->recordingClient();
        $persistence = (new AzureBlobSessionFactory())->createPersistence($this->context($client), [
            'account_name' => 'testaccount',
            'account_key' => base64_encode('fake-key-material'),
            'container' => 'my-sessions',
        ]);

        $uri = $this->firstRequestUri($client, $persistence);

        $this->assertStringContainsString('testaccount.blob.core.windows.net', $uri);
        $this->assertStringContainsString('/my-sessions/', $uri);
    }

    public function testTheBlobContainerDefaultsWhenNotConfigured(): void
    {
        $client = $this->recordingClient();
        $persistence = (new AzureBlobSessionFactory())->createPersistence($this->context($client), [
            'account_name' => 'testaccount',
            'account_key' => base64_encode('fake-key-material'),
        ]);

        $this->assertStringContainsString('/quiote-sessions/', $this->firstRequestUri($client, $persistence));
    }

    /**
     * An explicit endpoint is what points the backend at Azurite or a
     * sovereign cloud, so it has to survive rather than be overridden by the
     * public-origin default.
     */
    public function testAnExplicitBlobEndpointIsUsedInsteadOfThePublicOrigin(): void
    {
        $client = $this->recordingClient();
        $persistence = (new AzureBlobSessionFactory())->createPersistence($this->context($client), [
            'account_name' => 'testaccount',
            'account_key' => base64_encode('fake-key-material'),
            'endpoint' => 'http://127.0.0.1:10000/testaccount',
        ]);

        $uri = $this->firstRequestUri($client, $persistence);

        $this->assertStringStartsWith('http://127.0.0.1:10000/testaccount', $uri);
        $this->assertStringNotContainsString('blob.core.windows.net', $uri);
    }

    public function testAnEmptyBlobEndpointFallsBackToThePublicOrigin(): void
    {
        $client = $this->recordingClient();
        $persistence = (new AzureBlobSessionFactory())->createPersistence($this->context($client), [
            'account_name' => 'testaccount',
            'account_key' => base64_encode('fake-key-material'),
            'endpoint' => '',
        ]);

        $this->assertStringContainsString(
            'testaccount.blob.core.windows.net',
            $this->firstRequestUri($client, $persistence),
        );
    }

    public function testTheBlobFactoryExplainsItselfWithoutAnHttpClient(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Azure Blob-backed sessions need a ' . ClientInterface::class);

        (new AzureBlobSessionFactory())->createPersistence($this->context(null), ['account_name' => 'a']);
    }

    // -- table -------------------------------------------------------------

    public function testTheTableFactoryBuildsATableBackedPersistence(): void
    {
        $persistence = (new AzureTableSessionFactory())->createPersistence($this->context($this->recordingClient()), [
            'account_name' => 'testaccount',
            'account_key' => base64_encode('fake-key-material'),
            'table' => 'appsessions',
        ]);

        $this->assertInstanceOf(AzureTableSessionPersistence::class, $persistence);
        $this->assertNull($persistence->load('never-stored'));
    }

    public function testTheTableFactoryPointsAtTheConfiguredAccountAndTable(): void
    {
        $client = $this->recordingClient();
        $persistence = (new AzureTableSessionFactory())->createPersistence($this->context($client), [
            'account_name' => 'testaccount',
            'account_key' => base64_encode('fake-key-material'),
            'table' => 'appsessions',
        ]);

        $uri = $this->firstRequestUri($client, $persistence);

        $this->assertStringContainsString('testaccount.table.core.windows.net', $uri);
        $this->assertStringContainsString('appsessions', $uri);
    }

    public function testTheTableNameDefaultsWhenNotConfigured(): void
    {
        $client = $this->recordingClient();
        $persistence = (new AzureTableSessionFactory())->createPersistence($this->context($client), [
            'account_name' => 'testaccount',
            'account_key' => base64_encode('fake-key-material'),
        ]);

        $this->assertStringContainsString('sessions', $this->firstRequestUri($client, $persistence));
    }

    public function testAnExplicitTableEndpointIsUsedInsteadOfThePublicOrigin(): void
    {
        $client = $this->recordingClient();
        $persistence = (new AzureTableSessionFactory())->createPersistence($this->context($client), [
            'account_name' => 'testaccount',
            'account_key' => base64_encode('fake-key-material'),
            'endpoint' => 'http://127.0.0.1:10002/testaccount',
        ]);

        $uri = $this->firstRequestUri($client, $persistence);

        $this->assertStringStartsWith('http://127.0.0.1:10002/testaccount', $uri);
        $this->assertStringNotContainsString('table.core.windows.net', $uri);
    }

    public function testTheTableFactoryExplainsItselfWithoutAnHttpClient(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Azure Table-backed sessions need a ' . ClientInterface::class);

        (new AzureTableSessionFactory())->createPersistence($this->context(null), ['account_name' => 'a']);
    }

    // -- shared parameter handling -----------------------------------------

    public function testStrReturnsTheConfiguredValue(): void
    {
        $this->assertSame('value', AzureSessionParameters::str(['key' => 'value'], 'key', 'fallback'));
    }

    public function testStrFallsBackForAMissingKey(): void
    {
        $this->assertSame('fallback', AzureSessionParameters::str([], 'key', 'fallback'));
    }

    /**
     * Config sources hand back empty strings for unset environment
     * placeholders, so an empty value has to mean "unset" rather than
     * "deliberately blank" -- otherwise a missing env var silently addresses
     * an empty container.
     */
    public function testStrTreatsAnEmptyValueAsUnset(): void
    {
        $this->assertSame('fallback', AzureSessionParameters::str(['key' => ''], 'key', 'fallback'));
    }

    public function testStrFallsBackForANonStringValue(): void
    {
        $this->assertSame('fallback', AzureSessionParameters::str(['key' => 42], 'key', 'fallback'));
        $this->assertSame('fallback', AzureSessionParameters::str(['key' => null], 'key', 'fallback'));
        $this->assertSame('fallback', AzureSessionParameters::str(['key' => ['a']], 'key', 'fallback'));
    }

    public function testStrDefaultsToAnEmptyStringWithNoFallbackGiven(): void
    {
        $this->assertSame('', AzureSessionParameters::str([], 'key'));
    }

    public function testHttpClientReturnsTheBoundClient(): void
    {
        $client = $this->recordingClient();

        $this->assertSame($client, AzureSessionParameters::httpClient($this->context($client), 'Blob'));
    }

    /**
     * Failure path: the missing dependency has to name itself and the service
     * that wanted it, or the first symptom is a type error deep inside the
     * Azure client.
     */
    public function testHttpClientNamesTheServiceThatNeededIt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Azure Table-backed sessions need a');

        AzureSessionParameters::httpClient($this->context(null), 'Table');
    }
}
