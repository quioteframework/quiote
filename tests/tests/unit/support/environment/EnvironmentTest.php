<?php

use PHPUnit\Framework\TestCase;
use Quiote\Support\Environment\Environment;
use Quiote\Support\Environment\EnvironmentReaderInterface;
use Quiote\Support\Environment\SystemEnvironmentReader;

/**
 * The static facade fully-static call sites reach for. Must default to a
 * real SystemEnvironmentReader, accept an installed override, and restore
 * cleanly so one test's override cannot leak into another's.
 */
class EnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        Environment::useEnvironmentReader(null);
        parent::tearDown();
    }

    public function testInstanceDefaultsToSystemEnvironmentReader(): void
    {
        $this->assertInstanceOf(SystemEnvironmentReader::class, Environment::instance());
    }

    public function testInstanceMemoizesTheDefault(): void
    {
        $this->assertSame(Environment::instance(), Environment::instance());
    }

    /** Identity-only fake: these tests assert on which instance is installed, never call get(). */
    private function unusedReader(): EnvironmentReaderInterface
    {
        return new class implements EnvironmentReaderInterface {
            public function get(string $name): string|false
            {
                throw new \LogicException('not used in this test');
            }
        };
    }

    public function testUseEnvironmentReaderInstallsAnOverride(): void
    {
        $fake = $this->unusedReader();

        Environment::useEnvironmentReader($fake);

        $this->assertSame($fake, Environment::instance());
    }

    public function testUseEnvironmentReaderReturnsThePreviouslyInstalledReader(): void
    {
        $first = $this->unusedReader();
        $second = $this->unusedReader();

        Environment::useEnvironmentReader($first);
        $previous = Environment::useEnvironmentReader($second);

        $this->assertSame($first, $previous);
        $this->assertSame($second, Environment::instance());
    }

    public function testUseEnvironmentReaderWithNullDropsTheOverride(): void
    {
        Environment::useEnvironmentReader($this->unusedReader());

        Environment::useEnvironmentReader(null);

        $this->assertInstanceOf(SystemEnvironmentReader::class, Environment::instance());
    }
}
