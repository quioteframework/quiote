<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Context;
use Quiote\Storage\S3\S3SessionFactory;
use Quiote\Storage\S3\S3SessionPersistence;

/**
 * The `session` slot factory: an application must be able to name this class in
 * factories config and get a working backend, with no hand-written wrapper.
 */
final class S3SessionFactoryTest extends TestCase
{
    private function contextWithHttpClient(?ClientInterface $client): Context
    {
        return new class ($client) extends Context {
            public function __construct(private ?ClientInterface $client)
            {
            }

            #[\Override]
            public function getContainer(): \Quiote\DI\Container
            {
                $container = new \Quiote\DI\Container();
                if ($this->client !== null) {
                    $container->setFactory(ClientInterface::class, fn() => $this->client);
                }

                return $container;
            }
        };
    }

    private function httpClient(): ClientInterface
    {
        return new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new \Nyholm\Psr7\Response(404);
            }
        };
    }

    public function testItBuildsAPersistenceFromSlotParameters(): void
    {
        $persistence = (new S3SessionFactory())->createPersistence(
            $this->contextWithHttpClient($this->httpClient()),
            [
                'region' => 'eu-west-1',
                'bucket' => 'my-app-sessions',
                'access_key_id' => 'AKIA',
                'secret_access_key' => 'secret',
                'key_prefix' => 'sessions/',
            ],
        );

        $this->assertInstanceOf(S3SessionPersistence::class, $persistence);
        // A 404 from the stub client means "no such session", not an error.
        $this->assertNull($persistence->load('never-stored'));
    }

    /**
     * Failure path: the missing dependency has to name itself, or the first
     * symptom is a type error deep inside the S3 client.
     */
    public function testItExplainsItselfWithoutAnHttpClient(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('need a Psr\Http\Client\ClientInterface bound in the container');

        (new S3SessionFactory())->createPersistence($this->contextWithHttpClient(null), ['bucket' => 'b']);
    }
}
