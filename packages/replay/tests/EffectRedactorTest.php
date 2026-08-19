<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Cache\CacheInterface;
use Quiote\Config\Config;
use Quiote\Queue\JobPayload;
use Quiote\Queue\QueueDriverInterface;
use Quiote\Replay\Cache\RecordingCache;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Env\RecordingEnvironmentReader;
use Quiote\Replay\Http\RecordingHttpTransport;
use Quiote\Replay\Queue\RecordingQueueDriver;
use Quiote\Replay\Recording\EffectRedactor;
use Quiote\Replay\Recording\RecordingSession;
use Quiote\Replay\Recording\Redactor;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Environment\EnvironmentReaderInterface;

/**
 * Redaction used to stop at the request envelope: the only two consumers of {@see Redactor} in the
 * tree were `RecorderMiddleware` and the Propulsion query recorder, so every other recorder wrote
 * secrets into the cassette verbatim. These drive the real recording decorators through a
 * {@see RecordingSession}'s own ledger -- the wiring a live request gets -- and assert the secret
 * is not in the resulting effects.
 */
final class EffectRedactorTest extends TestCase
{
    /** @var list<string> */
    private const REPLAY_KEYS = ['replay.redact.headers', 'replay.redact.params', 'replay.redact.session', 'replay.redact.env', 'replay.redact.mode'];

    protected function tearDown(): void
    {
        foreach (self::REPLAY_KEYS as $key) {
            Config::remove($key);
        }
        parent::tearDown();
    }

    /** A session wired exactly as RecorderMiddleware wires one. */
    private function session(): RecordingSession
    {
        return new RecordingSession(maxBytes: 1_048_576, maxEffects: 2000);
    }

    private static function encode(EffectLedger $ledger): string
    {
        $payload = json_encode(array_map(
            static fn($effect): array => ['call' => $effect->call, 'result' => $effect->result],
            $ledger->all(),
        ));
        self::assertIsString($payload);

        return $payload;
    }

    // ---- environment variables ------------------------------------------------------------

    public function testASecretEnvironmentVariableValueNeverEntersTheLedger(): void
    {
        $session = $this->session();
        $reader = new RecordingEnvironmentReader($this->envReader(['DB_PASSWORD' => 'hunter2-actual']), $session->ledger());

        $this->assertSame('hunter2-actual', $reader->get('DB_PASSWORD'), 'The caller still gets the real value.');
        $this->assertStringNotContainsString('hunter2-actual', self::encode($session->ledger()));
        $this->assertSame('[REDACTED]', $session->ledger()->all()[0]->result);
        $this->assertSame(['name' => 'DB_PASSWORD'], $session->ledger()->all()[0]->call, 'The name is kept: it is what makes the effect readable.');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function secretEnvNames(): iterable
    {
        yield 'password suffix' => ['APP_DB_PASSWORD'];
        yield 'storage key' => ['AZURE_STORAGE_ACCOUNT_KEY'];
        yield 'stripe secret' => ['STRIPE_SECRET_KEY'];
        yield 'jwt private key' => ['JWT_PRIVATE_KEY'];
        yield 'connection string' => ['SQL_CONNECTION_STRING'];
        yield 'lowercase' => ['database_dsn'];
        yield 'auth' => ['AUTH_CLIENT_SECRET'];
        yield 'credential' => ['GOOGLE_APPLICATION_CREDENTIALS'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secretEnvNames')]
    public function testEveryConventionalSecretEnvNameShapeIsRedacted(string $name): void
    {
        $session = $this->session();
        $reader = new RecordingEnvironmentReader($this->envReader([$name => 'the-secret']), $session->ledger());

        $reader->get($name);

        $this->assertStringNotContainsString('the-secret', self::encode($session->ledger()), "$name was not redacted");
    }

    public function testANonSecretEnvironmentVariableIsKeptSoReplayCanReproduceIt(): void
    {
        $session = $this->session();
        $reader = new RecordingEnvironmentReader($this->envReader(['APP_ENV' => 'production']), $session->ledger());

        $reader->get('APP_ENV');

        $this->assertSame('production', $session->ledger()->all()[0]->result);
    }

    public function testAnUnsetSecretVariableRecordsFalseRatherThanARedactionMarker(): void
    {
        // false is getenv()'s "unset", not a secret -- and it is the distinction replay needs to
        // reproduce whichever branch the application took.
        $session = $this->session();
        $reader = new RecordingEnvironmentReader($this->envReader([]), $session->ledger());

        $this->assertFalse($reader->get('DB_PASSWORD'));
        $this->assertFalse($session->ledger()->all()[0]->result);
    }

    public function testTheEnvDenylistIsConfigurable(): void
    {
        Config::set('replay.redact.env', ['tenant'], true, false);
        $session = $this->session();
        $reader = new RecordingEnvironmentReader($this->envReader(['TENANT_ID' => 'acme', 'DB_PASSWORD' => 'pw']), $session->ledger());

        $reader->get('TENANT_ID');
        $reader->get('DB_PASSWORD');

        $this->assertSame('[REDACTED]', $session->ledger()->all()[0]->result);
        $this->assertSame('pw', $session->ledger()->all()[1]->result, 'Replacing the denylist replaces it wholesale.');
    }

    // ---- outbound HTTP --------------------------------------------------------------------

    public function testAnOutboundAuthorizationHeaderNeverEntersTheLedger(): void
    {
        $session = $this->session();
        $transport = new RecordingHttpTransport($this->httpTransport(), $session->ledger());
        $request = (new \Nyholm\Psr7\Request('GET', 'https://api.example.test/v1/things'))
            ->withHeader('Authorization', 'Bearer sk_live_realtoken')
            ->withHeader('X-Api-Key', 'apikey-real')
            ->withHeader('Accept', 'application/json');

        $transport->sendRequest($request);

        $encoded = self::encode($session->ledger());
        $this->assertStringNotContainsString('sk_live_realtoken', $encoded);
        $this->assertStringNotContainsString('apikey-real', $encoded);
        $headers = $session->ledger()->all()[0]->call['headers'];
        $this->assertIsArray($headers);
        $this->assertSame(['[REDACTED]'], $headers['Authorization']);
        $this->assertSame(['application/json'], $headers['Accept'], 'A header not on the denylist is untouched.');
    }

    public function testAResponseSetCookieHeaderNeverEntersTheLedger(): void
    {
        $session = $this->session();
        $response = (new \Nyholm\Psr7\Response(200))->withHeader('Set-Cookie', 'remote_session=realvalue');
        $transport = new RecordingHttpTransport($this->httpTransport($response), $session->ledger());

        $transport->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://api.example.test/x'));

        $this->assertStringNotContainsString('remote_session=realvalue', self::encode($session->ledger()));
    }

    public function testAResponseStatusAndNonSecretHeadersSurviveForReplay(): void
    {
        $session = $this->session();
        $response = (new \Nyholm\Psr7\Response(201))->withHeader('Content-Type', 'application/json');
        $transport = new RecordingHttpTransport($this->httpTransport($response), $session->ledger());

        $transport->sendRequest(new \Nyholm\Psr7\Request('GET', 'https://api.example.test/x'));

        $result = $session->ledger()->all()[0]->result;
        $this->assertIsArray($result);
        $this->assertSame(201, $result['status']);
        $this->assertIsArray($result['headers']);
        $this->assertSame(['application/json'], $result['headers']['Content-Type']);
    }

    // ---- cache ----------------------------------------------------------------------------

    public function testACachedValueUnderASecretKeyNeverEntersTheLedger(): void
    {
        $session = $this->session();
        $cache = new RecordingCache($this->arrayCache(['auth.token' => 'jwt-real-value']), $session->ledger());

        $this->assertSame('jwt-real-value', $cache->get('auth.token'), 'The caller still gets the real value.');
        $this->assertStringNotContainsString('jwt-real-value', self::encode($session->ledger()));
    }

    public function testASecretFieldInsideAStructuredCachedValueIsRedacted(): void
    {
        $session = $this->session();
        $cache = new RecordingCache($this->arrayCache(['user.42' => ['name' => 'Ada', 'password' => 'pw-real']]), $session->ledger());

        $cache->get('user.42');

        $encoded = self::encode($session->ledger());
        $this->assertStringNotContainsString('pw-real', $encoded);
        $this->assertStringContainsString('Ada', $encoded, 'Only the denied field is scrubbed.');
    }

    public function testAWrittenSecretCacheValueIsAlsoRedacted(): void
    {
        $session = $this->session();
        $cache = new RecordingCache($this->arrayCache(), $session->ledger());

        $cache->set('session:user:token', 'written-secret');

        $this->assertStringNotContainsString('written-secret', self::encode($session->ledger()));
    }

    public function testAnOrdinaryCachedValueIsKeptSoReplayCanReproduceIt(): void
    {
        $session = $this->session();
        $cache = new RecordingCache($this->arrayCache(['page.home' => '<h1>Home</h1>']), $session->ledger());

        $cache->get('page.home');

        $result = $session->ledger()->all()[0]->result;
        $this->assertIsArray($result);
        $this->assertSame('<h1>Home</h1>', $result['value']);
        $this->assertTrue($result['hit']);
    }

    public function testACacheMissStillRecordsItsMissState(): void
    {
        $session = $this->session();
        $cache = new RecordingCache($this->arrayCache(), $session->ledger());

        $cache->get('auth.token');

        $this->assertSame(['hit' => false], $session->ledger()->all()[0]->result);
    }

    // ---- queue ----------------------------------------------------------------------------

    public function testSecretJobParametersNeverEnterTheLedger(): void
    {
        $session = $this->session();
        $driver = new RecordingQueueDriver($this->nullQueue(), $session->ledger());

        $driver->push(new JobPayload('App\\Jobs\\SendInvite', ['email' => 'ada@example.test', 'token' => 'invite-real-token']));

        $encoded = self::encode($session->ledger());
        $this->assertStringNotContainsString('invite-real-token', $encoded);
        $this->assertStringContainsString('ada@example.test', $encoded, 'Only the denied field is scrubbed.');
    }

    public function testNestedSecretJobParametersAreRedactedAtAnyDepth(): void
    {
        $session = $this->session();
        $driver = new RecordingQueueDriver($this->nullQueue(), $session->ledger());

        $driver->push(new JobPayload('App\\Jobs\\Sync', ['creds' => ['nested' => ['secret' => 'deep-real']]]));

        $this->assertStringNotContainsString('deep-real', self::encode($session->ledger()));
    }

    // ---- database -------------------------------------------------------------------------

    public function testSecretColumnsInFetchedRowsNeverEnterTheLedger(): void
    {
        $session = $this->session();

        // The shape RecordingPdoStatement and the Doctrine recorder both produce: rows keyed by
        // column name, which is what makes them reachable by the params denylist.
        $session->ledger()->record(
            EffectKind::Db,
            'SELECT * FROM users',
            ['sql' => 'SELECT * FROM users', 'params' => []],
            [['id' => 1, 'email' => 'ada@example.test', 'password' => 'bcrypt-real-hash', 'token' => 'api-real-token']],
        );

        $encoded = self::encode($session->ledger());
        $this->assertStringNotContainsString('bcrypt-real-hash', $encoded);
        $this->assertStringNotContainsString('api-real-token', $encoded);
        $this->assertStringContainsString('ada@example.test', $encoded);
    }

    public function testSecretNamedBoundParametersNeverEnterTheLedger(): void
    {
        $session = $this->session();

        $session->ledger()->record(
            EffectKind::Db,
            'INSERT INTO users (email, password) VALUES (:email, :password)',
            ['sql' => 'INSERT INTO users (email, password) VALUES (:email, :password)', 'params' => ['email' => 'ada@example.test', 'password' => 'plaintext-real']],
            1,
        );

        $this->assertStringNotContainsString('plaintext-real', self::encode($session->ledger()));
    }

    public function testAPositionallyBoundParameterHasNoNameToMatchAndIsKept(): void
    {
        // Stated rather than hidden: a positional bind carries no column name, so there is nothing
        // to check it against -- the same rule Redactor::redactColumnValue() documents.
        $session = $this->session();

        $session->ledger()->record(
            EffectKind::Db,
            'INSERT INTO users (password) VALUES (?)',
            ['sql' => 'INSERT INTO users (password) VALUES (?)', 'params' => [1 => 'positional-value']],
            1,
        );

        $this->assertStringContainsString('positional-value', self::encode($session->ledger()));
    }

    // ---- mode and construction ------------------------------------------------------------

    public function testTheConfiguredRedactionModeAppliesToEffects(): void
    {
        Config::set('replay.redact.mode', 'hash', true, false);
        $session = $this->session();
        $reader = new RecordingEnvironmentReader($this->envReader(['DB_PASSWORD' => 'pw']), $session->ledger());

        $reader->get('DB_PASSWORD');

        // The env path replaces the value outright rather than hashing it -- the variable name
        // already identifies it and a digest of a deployment secret buys nothing.
        $this->assertSame('[REDACTED]', $session->ledger()->all()[0]->result);
    }

    public function testALedgerWithoutARedactorRecordsVerbatim(): void
    {
        // The replay-side construction: a cassette's effects were scrubbed when recorded, and
        // scrubbing again on read would only remove data replay needs.
        $ledger = new EffectLedger();
        $ledger->record(EffectKind::Env, 'DB_PASSWORD', ['name' => 'DB_PASSWORD'], 'pw');

        $this->assertSame('pw', $ledger->all()[0]->result);
    }

    public function testAnExplicitRedactorIsUsedInsteadOfConfig(): void
    {
        $redactor = new EffectRedactor(
            new Redactor([], ['nickname'], []),
            ['unusual'],
        );
        $ledger = new EffectLedger(redactor: $redactor);

        $ledger->record(EffectKind::Env, 'MY_UNUSUAL_VAR', ['name' => 'MY_UNUSUAL_VAR'], 'v');
        $ledger->record(EffectKind::Queue, 'push:J:[]', ['op' => 'push', 'params' => ['nickname' => 'ada']], null);

        $this->assertSame('[REDACTED]', $ledger->all()[0]->result);
        $params = $ledger->all()[1]->call['params'];
        $this->assertIsArray($params);
        $this->assertSame('[REDACTED]', $params['nickname']);
    }

    // ---- fakes ----------------------------------------------------------------------------

    /** @param array<string, string> $vars */
    private function envReader(array $vars): EnvironmentReaderInterface
    {
        return new class($vars) implements EnvironmentReaderInterface {
            /** @param array<string, string> $vars */
            public function __construct(private readonly array $vars)
            {
            }

            #[\Override]
            public function get(string $name): string|false
            {
                return $this->vars[$name] ?? false;
            }
        };
    }

    private function httpTransport(?\Psr\Http\Message\ResponseInterface $response = null): \Psr\Http\Client\ClientInterface
    {
        return new class($response ?? new \Nyholm\Psr7\Response(200)) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private readonly \Psr\Http\Message\ResponseInterface $response)
            {
            }

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->response;
            }
        };
    }

    /** @param array<string, mixed> $seed */
    private function arrayCache(array $seed = []): CacheInterface
    {
        return new class($seed) implements CacheInterface {
            /** @param array<string, mixed> $items */
            public function __construct(private array $items)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->items[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->items[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->items = [];

                return true;
            }

            /** @param iterable<mixed, array-key> $keys */
            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $out = [];
                foreach ($keys as $key) {
                    $out[(string)$key] = $this->get((string)$key, $default);
                }

                return $out;
            }

            /** @param iterable<array-key, mixed> $values */
            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set((string)$key, $value, $ttl);
                }

                return true;
            }

            /** @param iterable<mixed, array-key> $keys */
            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete((string)$key);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->items);
            }
        };
    }

    private function nullQueue(): QueueDriverInterface
    {
        return new class implements QueueDriverInterface {
            #[\Override]
            public function push(JobPayload $payload): void
            {
            }
        };
    }
}
