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
     * Checks if a field has no value (In web context this would only return true
     * when the strings length is 0 or the field is not set.
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
     * Checks if there is a value of a parameter is empty or not set.
     */
    public function isParameterValueEmpty(string $field): bool
    {
        $value = $this->getParameter($field);
        $empty = ($value === null || $value === '');
        $logger = \Quiote\Logging\Log::for($this);
        if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
            $logger->debug('[WebRequest][debug][isParameterValueEmpty] field=' . $field . ' empty=' . ($empty ? '1' : '0') . ' valueType=' . gettype($value));
        }
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
            $val = $cookies[$name];
            return $val === null || $val === '';
        }
        return true;
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
        try {
            $value = ArrayPathDefinition::getValue($field, $files, null);
        } catch (\Throwable) {
            $value = null;
        }

        $logger = \Quiote\Logging\Log::for($this);
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
