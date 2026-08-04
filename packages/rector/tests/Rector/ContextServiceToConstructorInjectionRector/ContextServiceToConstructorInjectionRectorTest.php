<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\Rector\ContextServiceToConstructorInjectionRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Fixture-driven, because the interesting cases are the ones that must come out *unchanged*: a
 * `getContext()` that is not a Quiote Context, and a `getService()` whose argument cannot be
 * resolved to a class. A rule that rewrites those is worse than a rule that does nothing.
 */
final class ContextServiceToConstructorInjectionRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function test(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    /**
     * @return \Iterator<array<string>>
     */
    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/configured_rule.php';
    }
}
