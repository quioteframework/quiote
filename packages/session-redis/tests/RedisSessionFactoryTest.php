<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Quiote\Context;
use Quiote\Session\Redis\RedisSessionFactory;
use Quiote\Session\Redis\RedisSessionPersistence;
use Quiote\Session\SessionPersistenceInterface;

/**
 * The `session` slot factory: an application must be able to name this class
 * in factories config and get a working backend, with no hand-written
 * wrapper. Predis connects lazily, so building the client here never touches
 * the network.
 */
final class RedisSessionFactoryTest extends TestCase
{
    private function context(): Context
    {
        return new class ('test') extends Context {
            public function __construct(string $name)
            {
                parent::__construct($name);
            }
        };
    }

    private function readProperty(SessionPersistenceInterface $persistence, string $name): mixed
    {
        $this->assertInstanceOf(RedisSessionPersistence::class, $persistence);

        return (new ReflectionProperty(RedisSessionPersistence::class, $name))->getValue($persistence);
    }

    public function testItBuildsAPersistenceFromSlotParameters(): void
    {
        $persistence = (new RedisSessionFactory())->createPersistence($this->context(), [
            'dsn' => 'redis://127.0.0.1:6379',
            'prefix' => 'app_session:',
            'ttl' => 3600,
        ]);

        $this->assertInstanceOf(RedisSessionPersistence::class, $persistence);
        $this->assertSame('app_session:', $this->readProperty($persistence, 'prefix'));
        $this->assertSame(3600, $this->readProperty($persistence, 'ttl'));
    }

    public function testItFallsBackToDefaultsWhenNoParametersAreGiven(): void
    {
        $persistence = (new RedisSessionFactory())->createPersistence($this->context(), []);

        $this->assertInstanceOf(RedisSessionPersistence::class, $persistence);
        $this->assertSame('session:', $this->readProperty($persistence, 'prefix'));
        $this->assertSame(1440, $this->readProperty($persistence, 'ttl'));
    }

    /**
     * Config sources hand strings back for everything, so a `ttl: 3600` read
     * out of YAML has to land as an int rather than as a string.
     */
    public function testANumericStringTtlIsCoercedToAnInt(): void
    {
        $persistence = (new RedisSessionFactory())->createPersistence($this->context(), ['ttl' => '900']);

        $this->assertSame(900, $this->readProperty($persistence, 'ttl'));
    }

    public function testANonNumericTtlFallsBackToTheDefault(): void
    {
        $persistence = (new RedisSessionFactory())->createPersistence($this->context(), ['ttl' => 'forever']);

        $this->assertSame(1440, $this->readProperty($persistence, 'ttl'));
    }

    public function testAnEmptyDsnFallsBackToLocalhost(): void
    {
        $persistence = (new RedisSessionFactory())->createPersistence($this->context(), ['dsn' => '']);

        $client = $this->readProperty($persistence, 'redis');
        $this->assertInstanceOf(ClientInterface::class, $client);
        $this->assertSame('127.0.0.1', $client->getConnection()->getParameters()->host);
        $this->assertSame(6379, (int) $client->getConnection()->getParameters()->port);
    }

    public function testTheConfiguredDsnReachesThePredisClient(): void
    {
        $persistence = (new RedisSessionFactory())->createPersistence($this->context(), [
            'dsn' => 'redis://redis.internal:6380',
        ]);

        $client = $this->readProperty($persistence, 'redis');
        $this->assertInstanceOf(ClientInterface::class, $client);
        $this->assertSame('redis.internal', $client->getConnection()->getParameters()->host);
        $this->assertSame(6380, (int) $client->getConnection()->getParameters()->port);
    }

    /**
     * An empty prefix is a deliberate choice (bare session ids as keys), so it
     * must survive rather than be treated as "unset" the way an empty DSN is.
     */
    public function testAnEmptyPrefixIsHonouredRatherThanDefaulted(): void
    {
        $persistence = (new RedisSessionFactory())->createPersistence($this->context(), ['prefix' => '']);

        $this->assertSame('', $this->readProperty($persistence, 'prefix'));
    }

    public function testANonStringPrefixFallsBackToTheDefault(): void
    {
        $persistence = (new RedisSessionFactory())->createPersistence($this->context(), ['prefix' => 42]);

        $this->assertSame('session:', $this->readProperty($persistence, 'prefix'));
    }
}
