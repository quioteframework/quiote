<?php

use PHPUnit\Framework\TestCase;
use Quiote\Support\Environment\EnvironmentReaderInterface;
use Quiote\Support\Environment\SystemEnvironmentReader;

/**
 * SystemEnvironmentReader is the production binding for
 * {@see EnvironmentReaderInterface} -- it must answer from the real `getenv()`
 * and from `$_ENV`, where a dotenv bootstrap puts what it loaded, matching its
 * own false-for-unset contract exactly.
 */
class SystemEnvironmentReaderTest extends TestCase
{
    private const VAR_NAME = 'QUIOTE_TEST_SYSTEM_ENV_READER_VAR';

    protected function tearDown(): void
    {
        putenv(self::VAR_NAME);
        unset($_ENV[self::VAR_NAME]);
        parent::tearDown();
    }

    public function testImplementsEnvironmentReaderInterface(): void
    {
        $this->assertInstanceOf(EnvironmentReaderInterface::class, new SystemEnvironmentReader());
    }

    public function testReadsARealEnvironmentVariable(): void
    {
        putenv(self::VAR_NAME . '=some-value');

        $this->assertSame('some-value', (new SystemEnvironmentReader())->get(self::VAR_NAME));
    }

    public function testReturnsFalseForAnUnsetVariable(): void
    {
        $this->assertFalse((new SystemEnvironmentReader())->get(self::VAR_NAME));
    }

    public function testReadsAnEmptyStringDistinctlyFromUnset(): void
    {
        putenv(self::VAR_NAME . '=');

        $this->assertSame('', (new SystemEnvironmentReader())->get(self::VAR_NAME));
    }

    /**
     * vlucas/phpdotenv's default createImmutable() writes `$_ENV` and `$_SERVER` and never calls
     * putenv(), because putenv()/getenv() are not thread-safe. Asking getenv() alone would answer
     * "unset" for everything such a bootstrap loaded.
     */
    public function testReadsAVariableADotenvBootstrapPutInTheEnvSuperglobal(): void
    {
        $_ENV[self::VAR_NAME] = 'from-dotenv';

        $this->assertSame('from-dotenv', (new SystemEnvironmentReader())->get(self::VAR_NAME));
    }

    /**
     * The platform's own variable is the one the deployment set, and an immutable dotenv repository
     * would not have overwritten it either.
     */
    public function testARealProcessVariableWinsOverTheSuperglobal(): void
    {
        putenv(self::VAR_NAME . '=from-process');
        $_ENV[self::VAR_NAME] = 'from-dotenv';

        $this->assertSame('from-process', (new SystemEnvironmentReader())->get(self::VAR_NAME));
    }

    public function testAnEmptyStringInTheSuperglobalIsStillDistinctFromUnset(): void
    {
        $_ENV[self::VAR_NAME] = '';

        $this->assertSame('', (new SystemEnvironmentReader())->get(self::VAR_NAME));
    }

    /**
     * The interface promises string|false, and the superglobal mirrors an environment whose values
     * are strings -- anything else in there is not an environment variable.
     */
    public function testANonStringInTheSuperglobalIsNotAnEnvironmentVariable(): void
    {
        $_ENV[self::VAR_NAME] = ['not', 'a', 'string'];

        $this->assertFalse((new SystemEnvironmentReader())->get(self::VAR_NAME));
    }

    /**
     * `$_SERVER` carries the request under CGI and FastCGI, so it is not part of the environment a
     * configuration value may read. Dotenv writes everything it puts there into `$_ENV` as well.
     */
    public function testTheServerSuperglobalIsNotConsulted(): void
    {
        $previous = $_SERVER[self::VAR_NAME] ?? null;
        $_SERVER[self::VAR_NAME] = 'from-server';

        try {
            $this->assertFalse((new SystemEnvironmentReader())->get(self::VAR_NAME));
        } finally {
            if ($previous === null) {
                unset($_SERVER[self::VAR_NAME]);
            } else {
                $_SERVER[self::VAR_NAME] = $previous;
            }
        }
    }
}
