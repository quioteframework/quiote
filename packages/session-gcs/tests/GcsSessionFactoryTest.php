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
use Quiote\Storage\Gcs\GcsSessionFactory;
use Quiote\Storage\Gcs\GcsSessionPersistence;

/**
 * The `session` slot factory: an application must be able to name this class
 * in factories config and get a working backend, with no hand-written
 * wrapper. Where the built backend actually points is only observable from
 * the requests it issues, so each test drives it through one operation and
 * reads the recorded request.
 */
final class GcsSessionFactoryTest extends TestCase
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

    /** @return array<string, mixed> */
    private function fullParameters(): array
    {
        return [
            'bucket' => 'my-app-sessions',
            'access_key' => 'GOOG1EXAMPLE',
            'secret_key' => 'hmac-secret',
            'object_prefix' => 'sessions/',
        ];
    }

    public function testItBuildsAPersistenceFromSlotParameters(): void
    {
        $persistence = (new GcsSessionFactory())
            ->createPersistence($this->context($this->recordingClient()), $this->fullParameters());

        $this->assertInstanceOf(GcsSessionPersistence::class, $persistence);
        $this->assertNull($persistence->load('never-stored'), 'a 404 means "no such session", not an error');
    }

    public function testItPointsAtTheConfiguredBucket(): void
    {
        $client = $this->recordingClient();
        $persistence = (new GcsSessionFactory())->createPersistence($this->context($client), $this->fullParameters());

        $uri = $this->firstRequestUri($client, $persistence);

        $this->assertStringStartsWith('https://storage.googleapis.com/my-app-sessions/', $uri);
    }

    public function testTheConfiguredObjectPrefixNamesTheStoredObject(): void
    {
        $client = $this->recordingClient();
        $persistence = (new GcsSessionFactory())->createPersistence(
            $this->context($client),
            [...$this->fullParameters(), 'object_prefix' => 'tenant-a/sess-'],
        );

        $uri = $this->firstRequestUri($client, $persistence);

        $this->assertStringContainsString('tenant-a/sess-sid-1.json', urldecode($uri));
    }

    public function testTheObjectPrefixDefaultsWhenNotConfigured(): void
    {
        $client = $this->recordingClient();
        $parameters = $this->fullParameters();
        unset($parameters['object_prefix']);

        $persistence = (new GcsSessionFactory())->createPersistence($this->context($client), $parameters);

        $this->assertStringContainsString('sessions/sid-1.json', urldecode($this->firstRequestUri($client, $persistence)));
    }

    /**
     * An explicit endpoint is what points the backend at a test double or an
     * S3-compatible gateway, so it has to survive rather than be overridden
     * by the public GCS origin.
     */
    public function testAnExplicitEndpointIsUsedInsteadOfThePublicOrigin(): void
    {
        $client = $this->recordingClient();
        $persistence = (new GcsSessionFactory())->createPersistence(
            $this->context($client),
            [...$this->fullParameters(), 'endpoint' => 'http://127.0.0.1:4443'],
        );

        $uri = $this->firstRequestUri($client, $persistence);

        $this->assertStringStartsWith('http://127.0.0.1:4443/my-app-sessions/', $uri);
        $this->assertStringNotContainsString('storage.googleapis.com', $uri);
    }

    /**
     * Config sources hand back empty strings for unset environment
     * placeholders, so an empty endpoint has to mean "unset" rather than
     * produce a request with no host at all.
     */
    public function testAnEmptyEndpointFallsBackToThePublicOrigin(): void
    {
        $client = $this->recordingClient();
        $persistence = (new GcsSessionFactory())->createPersistence(
            $this->context($client),
            [...$this->fullParameters(), 'endpoint' => ''],
        );

        $this->assertStringStartsWith(
            'https://storage.googleapis.com/',
            $this->firstRequestUri($client, $persistence),
        );
    }

    public function testANonStringParameterFallsBackToItsDefault(): void
    {
        $client = $this->recordingClient();
        $persistence = (new GcsSessionFactory())->createPersistence(
            $this->context($client),
            [...$this->fullParameters(), 'object_prefix' => 42, 'endpoint' => null],
        );

        $uri = urldecode($this->firstRequestUri($client, $persistence));

        $this->assertStringStartsWith('https://storage.googleapis.com/', $uri);
        $this->assertStringContainsString('sessions/sid-1.json', $uri);
    }

    /**
     * The HMAC credentials are what make the request acceptable to GCS, so
     * they have to reach the signer rather than be silently dropped.
     */
    public function testTheHmacCredentialsSignTheRequest(): void
    {
        $client = $this->recordingClient();
        $persistence = (new GcsSessionFactory())->createPersistence($this->context($client), $this->fullParameters());
        $persistence->load('sid-1');

        /** @var list<RequestInterface> $requests */
        $requests = $client->requests; // @phpstan-ignore property.notFound

        $this->assertStringContainsString('GOOG1EXAMPLE', $requests[0]->getHeaderLine('Authorization'));
    }

    /**
     * Failure path: the missing dependency has to name itself, or the first
     * symptom is a type error deep inside the GCS client.
     */
    public function testItExplainsItselfWithoutAnHttpClient(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GCS-backed sessions need a ' . ClientInterface::class);

        (new GcsSessionFactory())->createPersistence($this->context(null), ['bucket' => 'b']);
    }
}
