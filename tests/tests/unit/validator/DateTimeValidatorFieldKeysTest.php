<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Validator\DateTimeValidator;

/**
 * The pieces of DateTimeValidator that read a date out of separate submitted
 * fields, and out of a unix-millisecond value.
 *
 * A form that posts year/month/day as their own inputs names those fields by
 * key, and the keys come in three spellings at once: the legacy
 * `DateDefinitions::MONTH` constant name, a bare `MONTH`, and the numeric
 * constant value. All three have to land on the same field, or a form written
 * against one spelling silently validates nothing.
 *
 * These are pure functions over their input, so they are exercised directly
 * rather than through a full locale-aware validation run.
 */
final class DateTimeValidatorFieldKeysTest extends TestCase
{
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(DateTimeValidator::class, $method))
            ->invokeArgs(new DateTimeValidator(), $arguments);
    }

    // --- whole numbers ------------------------------------------------------

    #[DataProvider('wholeNumbers')]
    public function testWhatCountsAsAWholeNumber(string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->invoke('isWholeNumber', $value));
    }

    /** @return array<string, array{0: string,1: bool}> */
    public static function wholeNumbers(): array
    {
        return [
            'digits' => ['2024', true],
            'zero' => ['0', true],
            'negative' => ['-5', true],
            'leading zeroes' => ['007', true],
            'decimal' => ['1.5', false],
            'empty' => ['', false],
            'spaced' => [' 12', false],
            'trailing text' => ['12abc', false],
            'plus sign' => ['+12', false],
            'not a number' => ['abc', false],
        ];
    }

    // --- field keys ---------------------------------------------------------

    #[DataProvider('fieldKeys')]
    public function testAFieldKeyResolvesToItsDateComponent(mixed $key, ?string $expectedField): void
    {
        $resolved = $this->invoke('normalizeFieldKey', $key);

        if ($expectedField === null) {
            $this->assertNull($resolved);

            return;
        }

        $this->assertIsArray($resolved);
        $this->assertSame($expectedField, $resolved['field']);
    }

    /** @return array<string, array{0: mixed, 1: ?string}> */
    public static function fieldKeys(): array
    {
        return [
            'legacy constant name' => ['DateDefinitions::MONTH', 'month'],
            'bare name' => ['MONTH', 'month'],
            'numeric constant' => [2, 'month'],
            'numeric constant as string' => ['2', 'month'],
            'year by letter' => ['Y', 'year'],
            'date means day' => ['DATE', 'day'],
            'day of month means day' => ['DateDefinitions::DAY_OF_MONTH', 'day'],
            'hour of day' => ['HOUR_OF_DAY', 'hour'],
            'milliseconds in day' => ['MILLISECONDS_IN_DAY', 'milliseconds_in_day'],
            'unknown name' => ['FORTNIGHT', null],
            'unmapped number' => [99, null],
            'null' => [null, null],
            'array' => [['month'], null],
        ];
    }

    /** Names are matched case-insensitively and trimmed, since they come from config. */
    public function testFieldKeyNamesAreCaseInsensitiveAndTrimmed(): void
    {
        foreach (['month', 'Month', '  MONTH  ', "\\DateDefinitions::MONTH"] as $spelling) {
            $resolved = $this->invoke('normalizeFieldKey', $spelling);

            $this->assertIsArray($resolved, $spelling . ' must resolve');
            $this->assertSame('month', $resolved['field']);
        }
    }

    /**
     * Month is the one component the legacy field constants count from zero,
     * so it carries a flag the others do not -- without it, every submitted
     * month would land one month late.
     */
    public function testOnlyMonthIsMarkedZeroBased(): void
    {
        $month = $this->invoke('normalizeFieldKey', 'MONTH');
        $day = $this->invoke('normalizeFieldKey', 'DAY');

        $this->assertIsArray($month);
        $this->assertIsArray($day);
        $this->assertTrue($month['zero_based'] ?? false);
        $this->assertFalse($day['zero_based'] ?? false);
    }

    // --- unix milliseconds --------------------------------------------------

    public function testAMillisecondTimestampBecomesThatInstant(): void
    {
        $date = $this->invoke('fromUnixMilliseconds', '1700000000000', 'UTC');

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame('2023-11-14 22:13:20', $date->format('Y-m-d H:i:s'));
    }

    /** The sub-second part is what distinguishes this from a plain unix timestamp. */
    public function testTheMillisecondPartIsKept(): void
    {
        $date = $this->invoke('fromUnixMilliseconds', '1700000000123', 'UTC');

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame('123000', $date->format('u'));
    }

    public function testTheResultIsMovedIntoTheRequestedTimezone(): void
    {
        $date = $this->invoke('fromUnixMilliseconds', '1700000000000', 'Europe/Helsinki');

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame('Europe/Helsinki', $date->getTimezone()->getName());
        $this->assertSame(1700000000, $date->getTimestamp(), 'the instant is unchanged by the zone');
    }

    public function testTheEpochItselfIsRepresentable(): void
    {
        $date = $this->invoke('fromUnixMilliseconds', '0', 'UTC');

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame(0, $date->getTimestamp());
    }

    /**
     * Rounding the microsecond part can carry all the way to a whole second;
     * the result must still be a valid instant rather than one with 1_000_000
     * microseconds in it.
     */
    public function testAValueRoundingUpToAWholeSecondStaysValid(): void
    {
        $date = $this->invoke('fromUnixMilliseconds', '1699999999999.9999', 'UTC');

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertLessThan(1_000_000, (int) $date->format('u'));
    }
}
