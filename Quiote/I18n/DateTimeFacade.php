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
 *  - Provide explicit timezone & locale handling without custom Olson DB.
 * This is intentionally minimal; extend only when concrete application usages require more tokens.
 *
 * Formatting and parsing both go through {@see IntlDateFormatter}: ext-intl is a hard requirement
 * of the framework (see composer.json), so there is no second implementation to keep in step --
 * one that would, being locale-blind, quietly disagree with this one.
 *
 * The supported tokens are spelled the same way in ICU, so a pattern is handed to ICU as written;
 * {@see assertSupportedTokens()} is what keeps a pattern inside that subset.
 */
final class DateTimeFacade
{
    /**
     * The legacy pattern tokens this class accepts, each spelled identically in ICU.
     * @var list<string>
     */
    private const array SUPPORTED_TOKENS = ['yyyy', 'MM', 'dd', 'HH', 'mm', 'ss'];

    /**
     * Per-worker cache of IntlDateFormatter instances keyed by
     * "locale|tz|pattern". Constructing one loads ICU locale data every
     * time; reuse is safe since setLenient(false) (the only mutable knob we
     * touch) is applied identically to every cached instance regardless of
     * whether the caller is formatting or parsing -- it only affects
     * parse() leniency, format() ignores it.
     * @var array<string, IntlDateFormatter>
     */
    private static array $formatterCache = [];

    private static function getFormatter(string $locale, string $intlTz, string $icuPattern): IntlDateFormatter
    {
        $key = $locale . '|' . $intlTz . '|' . $icuPattern;
        if (isset(self::$formatterCache[$key])) {
            return self::$formatterCache[$key];
        }
        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $intlTz,
            IntlDateFormatter::GREGORIAN,
            $icuPattern
        );
        $formatter->setLenient(false);
        return self::$formatterCache[$key] = $formatter;
    }

    /**
     * Format a DateTime using a legacy-style pattern.
     *
     * @throws RuntimeException if the pattern holds an unsupported token, or ICU cannot format the
     *         value.
     */
    public static function format(DateTimeInterface $dt, string $pattern, ?string $locale = null): string
    {
        self::assertSupportedTokens($pattern);

        $intlTz = self::normalizeIntlTimezoneId($dt->getTimezone()->getName());
        $formatter = self::getFormatter($locale ?? \Locale::getDefault(), $intlTz, $pattern);

        $result = $formatter->format($dt);
        if ($result === false) {
            throw new RuntimeException('IntlDateFormatter failed to format datetime');
        }

        return $result;
    }

    /**
     * Parse a datetime string according to a legacy-style pattern.
     * Supports fixed-width tokens: yyyy, MM, dd, HH, mm, ss (24h clock).
     *
     * The whole value must be consumed, so trailing text is a parse failure rather than something
     * silently ignored.
     *
     * @throws RuntimeException if the pattern holds an unsupported token, or the value does not
     *         parse against it.
     */
    public static function parse(string $value, string $pattern, ?string $timezone = null, ?string $locale = null): DateTimeImmutable
    {
        $tz = new DateTimeZone($timezone ?: 'UTC');
        self::assertSupportedTokens($pattern);

        $intlTz = self::normalizeIntlTimezoneId($tz->getName());
        $formatter = self::getFormatter($locale ?? \Locale::getDefault(), $intlTz, $pattern);

        $pos = 0;
        $ts = $formatter->parse($value, $pos);
        if ($ts === false || $pos !== strlen($value)) {
            throw new RuntimeException("Failed to parse datetime '$value' with pattern '$pattern'");
        }

        return (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    }

    /**
     * IntlDateFormatter rejects raw "+02:00" style identifiers; it expects e.g. "GMT+02:00".
     *
     * PHP normalizes an offset zone to "+HH:MM" whichever spelling it was constructed from
     * (`new DateTimeZone('+0200')` reports "+02:00"), so that is the only offset form reaching
     * here. A named zone ("UTC", "Europe/Helsinki") is passed through -- ICU takes those as-is.
     */
    private static function normalizeIntlTimezoneId(string $name): string
    {
        if (preg_match('/^([+-])(\d{2}):(\d{2})$/', $name, $m)) {
            return 'GMT' . $m[1] . $m[2] . ':' . $m[3];
        }

        return $name;
    }

    /**
     * Rejects patterns outside the supported token subset.
     *
     * A run of two or more letters is a pattern token and must be one this class supports; a
     * single letter is left alone, so a literal separator ("T" in `yyyy-MM-ddTHH:mm:ss`) still
     * passes. Widening the subset means adding to {@see SUPPORTED_TOKENS}, not removing this.
     *
     * @throws RuntimeException if the pattern holds a token outside the subset.
     */
    private static function assertSupportedTokens(string $pattern): void
    {
        if (preg_match_all('/([a-zA-Z]+)/', $pattern, $matches)) {
            foreach ($matches[1] as $token) {
                if (strlen($token) > 1 && !in_array($token, self::SUPPORTED_TOKENS, true)) {
                    throw new RuntimeException(sprintf(
                        "Unsupported date pattern token '%s'; supported tokens are: %s.",
                        $token,
                        implode(', ', self::SUPPORTED_TOKENS)
                    ));
                }
            }
        }
    }
}
