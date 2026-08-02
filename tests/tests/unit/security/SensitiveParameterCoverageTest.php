<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every parameter that receives a secret must carry `#[\SensitiveParameter]`.
 *
 * This is not a style rule. PHP includes argument values in the string form of
 * a stack trace, and {@see \Quiote\Middleware\ErrorHandlingMiddleware} logs
 * `$e->getTraceAsString()` for every uncaught throwable. Without the attribute,
 * any exception raised beneath a password hash, a bearer token or a client
 * secret writes that value into the error log in plaintext -- an incident that
 * turns a stack trace into a credential dump. With it, the engine substitutes
 * `Object(SensitiveParameterValue)` instead.
 *
 * Asserted by reflection rather than by grep so it holds for whatever the
 * signature is today, and stated as a table so a newly added credential
 * parameter is one line to cover.
 */
class SensitiveParameterCoverageTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('credentialParameters')]
    public function testCredentialParametersAreMarkedSensitive(string $class, string $method, string $parameter): void
    {
        if (!class_exists($class) && !interface_exists($class)) {
            $this->markTestSkipped($class . ' is not installed in this environment.');
        }

        $reflection = new ReflectionMethod($class, $method);
        $found = null;
        foreach ($reflection->getParameters() as $candidate) {
            if ($candidate->getName() === $parameter) {
                $found = $candidate;
                break;
            }
        }

        $this->assertNotNull($found, sprintf('%s::%s() has no $%s parameter', $class, $method, $parameter));
        $this->assertNotEmpty(
            $found->getAttributes(SensitiveParameter::class),
            sprintf(
                '%s::%s($%s) receives a secret and must carry #[\SensitiveParameter], or it lands in '
                . 'getTraceAsString() and therefore in the error log.',
                $class,
                $method,
                $parameter,
            ),
        );
    }

    /** @return array<string, array{class-string, string, string}> */
    public static function credentialParameters(): array
    {
        return [
            'hasher contract: hash' => [\Quiote\Security\Auth\PasswordHasherInterface::class, 'hash', 'plaintext'],
            'hasher contract: verify plaintext' => [\Quiote\Security\Auth\PasswordHasherInterface::class, 'verify', 'plaintext'],
            'hasher contract: verify hash' => [\Quiote\Security\Auth\PasswordHasherInterface::class, 'verify', 'hash'],
            'hasher contract: needsRehash' => [\Quiote\Security\Auth\PasswordHasherInterface::class, 'needsRehash', 'hash'],
            // The attribute is read from the function actually invoked, so the
            // implementation needs it too -- marking only the interface would
            // redact nothing at runtime.
            'default hasher: hash' => [\Quiote\Security\Auth\Hasher\DefaultPasswordHasher::class, 'hash', 'plaintext'],
            'default hasher: verify plaintext' => [\Quiote\Security\Auth\Hasher\DefaultPasswordHasher::class, 'verify', 'plaintext'],
            'default hasher: verify hash' => [\Quiote\Security\Auth\Hasher\DefaultPasswordHasher::class, 'verify', 'hash'],
            'default hasher: needsRehash' => [\Quiote\Security\Auth\Hasher\DefaultPasswordHasher::class, 'needsRehash', 'hash'],
            'stored hash on the identity' => [\Quiote\Security\Auth\Identity\InMemoryUserIdentity::class, '__construct', 'passwordHash'],
            'token validator contract' => [\Quiote\Security\Auth\TokenValidatorInterface::class, 'validate', 'token'],
            'jwt validator' => [\Quiote\Security\Auth\JwtTokenValidator::class, 'validate', 'token'],
            'oauth introspection' => [\Quiote\Security\Auth\IntrospectionClient::class, 'introspect', 'token'],
            'mcp authenticator contract' => [\Quiote\Mcp\Auth\McpAuthenticatorInterface::class, 'authenticate', 'token'],
            'mcp static token: expected' => [\Quiote\Mcp\Auth\StaticTokenAuthenticator::class, '__construct', 'expectedToken'],
            'mcp static token: submitted' => [\Quiote\Mcp\Auth\StaticTokenAuthenticator::class, 'authenticate', 'token'],
            'csrf token storage' => [\Quiote\Security\Csrf\SessionTokenStorage::class, 'setToken', 'token'],
        ];
    }

    /**
     * The property the attribute is actually there to protect: that a throw
     * beneath a marked parameter does not put the secret in the trace string.
     *
     * `zend.exception_ignore_args` is forced off for the duration. It defaults
     * on in most CLI/production builds, which drops *all* arguments and would
     * make this pass whether or not the attribute is present -- a green test
     * that proves nothing. Turning it off is the configuration the attribute
     * actually has to survive, and it is also the configuration a developer
     * debugging a live incident is most likely to have switched on.
     */
    public function testAMarkedParameterIsRedactedFromTheTraceString(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $this->assertRedactedInTrace();
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    private function assertRedactedInTrace(): void
    {
        $hasher = new class implements \Quiote\Security\Auth\PasswordHasherInterface {
            public function hash(#[\SensitiveParameter] string $plaintext): string
            {
                throw new RuntimeException('hashing blew up');
            }

            public function verify(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $hash): bool
            {
                return false;
            }

            public function needsRehash(#[\SensitiveParameter] string $hash): bool
            {
                return false;
            }
        };

        try {
            $hasher->hash('hunter2-the-actual-password');
            $this->fail('expected the hasher to throw');
        } catch (RuntimeException $e) {
            $trace = $e->getTraceAsString();
            $this->assertStringNotContainsString('hunter2-the-actual-password', $trace);
            $this->assertStringContainsString('SensitiveParameterValue', $trace);
        }
    }
}
