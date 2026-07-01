<?php
declare(strict_types=1);

namespace Quiote\I18n;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use RuntimeException;

/**
 * Lightweight modern replacement for legacy DateFormat / calendar stack.
 * Responsibilities:
 *  - Parse simple datetime strings with a subset of legacy pattern tokens (yyyy, MM, dd, HH, mm, ss)
 *  - Format DateTimeInterface using same subset
 *  - Delegate to IntlDateFormatter when ext/intl present; fallback to manual formatting otherwise.
 *  - Provide explicit timezone & locale handling without custom Olson DB.
 * This is intentionally minimal; extend only when concrete application usages require more tokens.
 */
final class DateTimeFacade
{
    /** Map legacy pattern tokens to ICU equivalents (most overlap). */
    private static array $tokenMap = [
        'yyyy' => 'yyyy',
        'MM'   => 'MM',
        'dd'   => 'dd',
        'HH'   => 'HH',
        'mm'   => 'mm',
        'ss'   => 'ss',
    ];

    /**
     * Format a DateTime using a legacy-style pattern.
     */
    public static function format(DateTimeInterface $dt, string $pattern, ?string $locale = null): string
    {
        self::assertSupportedTokens($pattern);

        $icuPattern = self::toIcuPattern($pattern);
        if (class_exists(IntlDateFormatter::class)) {
            $tzName = $dt->getTimezone()->getName();
            $intlTz = self::normalizeIntlTimezoneId($tzName);
            $formatter = new IntlDateFormatter(
                $locale ?? \Locale::getDefault(),
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                $intlTz,
                IntlDateFormatter::GREGORIAN,
                $icuPattern
            );
            $result = $formatter->format($dt);
            if ($result === false) {
                throw new RuntimeException('IntlDateFormatter failed to format datetime');
            }
            return $result;
        }
        // Fallback: naive replacement using PHP date() format equivalents
        $php = self::legacyToPhpDatePattern($pattern);
        return $dt->format($php);
    }

    /**
     * Parse a datetime string according to a legacy-style pattern.
     * Supports fixed-width tokens: yyyy, MM, dd, HH, mm, ss (24h clock).
     */
    public static function parse(string $value, string $pattern, ?string $timezone = null, ?string $locale = null): DateTimeImmutable
    {
        $tz = new DateTimeZone($timezone ?: 'UTC');
        self::assertSupportedTokens($pattern);

        $icuPattern = self::toIcuPattern($pattern);
        if (class_exists(IntlDateFormatter::class)) {
            $intlTz = self::normalizeIntlTimezoneId($tz->getName());
            $formatter = new IntlDateFormatter(
                $locale ?? \Locale::getDefault(),
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                $intlTz,
                IntlDateFormatter::GREGORIAN,
                $icuPattern
            );
            $formatter->setLenient(false); // Enforce strict parsing
            $pos = 0;
            $ts = $formatter->parse($value, $pos);
            if ($ts === false || $pos !== strlen($value)) {
                throw new RuntimeException("Failed to parse datetime '$value' with pattern '$pattern'");
            }
            return (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
        }
        // Manual fallback: build regex from pattern
        $regex = preg_quote($pattern, '#');
        $map = [
            'yyyy' => '(?P<year>\d{4})',
            'MM'   => '(?P<month>\d{2})',
            'dd'   => '(?P<day>\d{2})',
            'HH'   => '(?P<hour>\d{2})',
            'mm'   => '(?P<minute>\d{2})',
            'ss'   => '(?P<second>\d{2})',
        ];
        foreach ($map as $token => $rx) {
            $regex = str_replace($token, $rx, $regex);
        }
        if (!preg_match('#^' . $regex . '$#', $value, $m)) {
            throw new RuntimeException("Value '$value' does not match pattern '$pattern'");
        }
        $year = (int)$m['year'];
        $month = (int)$m['month'];
        $day = (int)$m['day'];
        $hour = (int)($m['hour'] ?? 0);
        $minute = (int)($m['minute'] ?? 0);
        $second = (int)($m['second'] ?? 0);
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), $tz);
    }

    private static function toIcuPattern(string $pattern): string
    {
        return $pattern; // direct pass-through; validation happens in assertSupportedTokens when intl missing
    }

    private static function legacyToPhpDatePattern(string $pattern): string
    {
        self::assertSupportedTokens($pattern);

        // Basic translation for subset; keep literal separators.
        $map = [
            'yyyy' => 'Y',
            'MM'   => 'm',
            'dd'   => 'd',
            'HH'   => 'H',
            'mm'   => 'i',
            'ss'   => 's',
        ];
        return strtr($pattern, $map);
    }

    /**
     * IntlDateFormatter rejects raw "+02:00" style identifiers; it expects e.g. "GMT+02:00".
     * Convert offset forms (+HHMM, +HH:MM, -HHMM, -HH:MM) to GMT-prefixed variant.
     */
    private static function normalizeIntlTimezoneId(string $name): string
    {
        if (preg_match('/^([+-])(\d{2}):(\d{2})$/', $name, $m)) {
            return 'GMT' . $m[1] . $m[2] . ':' . $m[3];
        }
        if (preg_match('/^([+-])(\d{2})(\d{2})$/', $name, $m)) {
            return 'GMT' . $m[1] . $m[2] . ':' . $m[3];
        }
        return $name;
    }

    private static function assertSupportedTokens(string $pattern): void
    {
        if (preg_match_all('/([a-zA-Z]+)/', $pattern, $matches)) {
            foreach ($matches[1] as $token) {
                if (!isset(self::$tokenMap[$token])) {
                    if (strlen($token) > 1) {
                        throw new RuntimeException("Unsupported date pattern token '$token' without intl extension");
                    }
                }
            }
        }
    }
}
