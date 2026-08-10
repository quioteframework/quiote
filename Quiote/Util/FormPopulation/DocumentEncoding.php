<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

use Quiote\Exception\QuioteException;

/**
 * The character encoding a document is being populated in, and the conversions
 * between it and UTF-8.
 *
 * DOM works in UTF-8 internally, but a field name read out of the document and
 * a value written back into it both have to be in the document's own encoding
 * -- otherwise a name like "stra&szlig;e" never matches the submitted
 * parameter, and a populated value arrives mojibaked. Both directions are
 * needed, which is why this is one object rather than two helpers.
 *
 * ISO-8859-1 is converted with mbstring, which every build has; anything else
 * needs iconv, so an encoding that would silently fail is refused up front
 * instead.
 */
final readonly class DocumentEncoding
{
    public const string UTF_8 = 'utf-8';

    public const string ISO_8859_1 = 'iso-8859-1';

    private function __construct(public string $name, public bool $isUtf8) {}

    /**
     * @throws QuioteException if the encoding needs iconv and iconv is absent.
     */
    public static function named(string $encoding): self
    {
        $normalized = strtolower($encoding);
        $isUtf8 = $normalized === self::UTF_8;

        if (!$isUtf8 && $normalized !== self::ISO_8859_1 && !function_exists('iconv')) {
            throw new QuioteException(
                'No iconv module available, input encoding "' . $normalized . '" cannot be handled.'
            );
        }

        return new self($normalized, $isUtf8);
    }

    public static function utf8(): self
    {
        return new self(self::UTF_8, true);
    }

    /**
     * Converts a value from this encoding to UTF-8, recursing into arrays.
     *
     * Used on the way *in*: a field name lifted out of the document is in the
     * document's encoding and has to be UTF-8 to match a submitted parameter.
     */
    public function toUtf8(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->toUtf8($item), $value);
        }

        $string = self::scalarString($value);

        if ($this->name === self::ISO_8859_1) {
            return mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
        }

        return iconv($this->name, self::UTF_8, $string);
    }

    /**
     * Converts a value from UTF-8 to this encoding, recursing into arrays.
     *
     * Used on the way *out*: a value about to be written into the document has
     * to be in the document's own encoding.
     */
    public function fromUtf8(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->fromUtf8($item), $value);
        }

        $string = self::scalarString($value);

        if ($this->name === self::ISO_8859_1) {
            return mb_convert_encoding($string, 'ISO-8859-1');
        }

        return iconv(self::UTF_8, $this->name, $string);
    }

    /**
     * A value's string form for conversion, using the same rule as a PHP cast
     * and an empty string for anything that has no meaningful one.
     */
    private static function scalarString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
