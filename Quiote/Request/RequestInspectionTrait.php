<?php

declare(strict_types=1);

namespace Quiote\Request;

use InvalidArgumentException;
use Quiote\Util\ArrayPathDefinition;

/**
 * "Is this field empty/absent?" convenience helpers shared by WebRequest,
 * one per input source (parameter, cookie, header, file).
 */
trait RequestInspectionTrait
{
    /**
     * @var array<string, string>
     */
    private array $sourceNames = ['parameters' => 'parameter', 'cookies' => 'cookie', 'files' => 'file', 'headers' => 'header'];

    /**
     * Checks if a field has no value: it is not set, or it is null, an empty
     * string or an empty array (see isEmptyValue()).
     */
    public function isValueEmpty(string $source, string $field): bool
    {
        $funcname = 'is' . $this->sourceNames[$source] . 'ValueEmpty';
        if (is_callable([$this, $funcname])) {
            return (bool)$this->$funcname($field);
        }
        throw new InvalidArgumentException("Invalid source name '$source'");
    }

    /**
     * Whether a request value counts as "no value": null (absent), an empty
     * string or an empty array. A `required` validator rejects such a value and
     * a non-required one skips it, so `[]` posted to an IsNotEmptyValidator
     * neither succeeds nor provides its dependency tokens.
     */
    public static function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * Checks if a parameter is not set or its value is empty (see isEmptyValue()).
     */
    public function isParameterValueEmpty(string $field): bool
    {
        $value = $this->getParameter($field);
        $empty = self::isEmptyValue($value);
        \Quiote\Logging\Log::for($this)->debugWith(
            static fn(): string => 'parameter "' . $field . '" empty=' . ($empty ? '1' : '0') . ' valueType=' . gettype($value)
        );
        return $empty;
    }

    /**
     * Indicates whether or not a Cookie exists.
     */
    public function hasCookie(string $name): bool
    {
        if (array_key_exists($name, $this->getCookieParams())) {
            return true;
        }
        try {
            return ArrayPathDefinition::hasValue($name, $this->getCookieParams());
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Checks if there is a value of a cookie is empty or not set.
     */
    public function isCookieValueEmpty(string $name): bool
    {
        // Explicitly inspect cookie params to avoid indirect parameter precedence side-effects.
        $cookies = $this->getCookieParams();
        if (array_key_exists($name, $cookies)) {
            return self::isEmptyValue($cookies[$name]);
        }
        // A nested cookie (prefs[theme]) is addressed by its bracket path.
        try {
            $val = ArrayPathDefinition::getValue($name, $cookies, null);
        } catch (InvalidArgumentException $e) {
            \Quiote\Logging\Log::for($this)->debug(
                'treating cookie "' . $name . '" as empty, its name is not a valid path: ' . $e->getMessage()
            );
            return true;
        }
        return self::isEmptyValue($val);
    }

    /**
     * Checks if there is a value of a header is empty or not set.
     */
    public function isHeaderValueEmpty(string $name): bool
    {
        // PSR-7 getHeader() returns an array; empty array means header absent.
        // We consider a header "empty" if it is not present OR if all values are
        // empty strings once concatenated (getHeaderLine == '').
        if (!$this->hasHeader($name)) {
            return true;
        }
        return $this->getHeaderLine($name) === '';
    }

    /**
     * Checks if a file is empty, i.e. not set or set, but not actually uploaded.
     */
    public function isFileValueEmpty(string $field): bool
    {
        $files = $this->getUploadedFiles();

        // Try to get the file value - could be nested in array structure
        $logger = \Quiote\Logging\Log::for($this);
        try {
            $value = ArrayPathDefinition::getValue($field, $files, null);
        } catch (InvalidArgumentException $e) {
            $logger->debug(
                'treating file "' . $field . '" as empty, its name is not a valid path: ' . $e->getMessage()
            );
            $value = null;
        }

        if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
            $logger->debug(
                '[WebRequest][debug][isFileValueEmpty] field=' . $field .
                ' empty=' . ($value === null ? '1' : '0') .
                ' valueType=' . gettype($value)
            );
        }

        if ($value === null) {
            return true;
        }

        if ($value instanceof \Psr\Http\Message\UploadedFileInterface) {
            return false;
        }

        // Invalid type - treat as empty
        return true;
    }
}
