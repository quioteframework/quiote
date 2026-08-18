<?php

use PHPUnit\Framework\TestCase;
use Quiote\Support\Environment\EnvironmentReaderInterface;
use Quiote\Support\Environment\SystemEnvironmentReader;

/**
 * SystemEnvironmentReader is the production binding for
 * {@see EnvironmentReaderInterface} -- it must delegate to the real
 * `getenv()`, matching its own false-for-unset contract exactly.
 */
class SystemEnvironmentReaderTest extends TestCase
{
    private const VAR_NAME = 'QUIOTE_TEST_SYSTEM_ENV_READER_VAR';

    protected function tearDown(): void
    {
        putenv(self::VAR_NAME);
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
}
