<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

use Quiote\Util\ParameterHolder;

/**
 * The fields configured to be left exactly as the view rendered them.
 *
 * Matching is on the front of the resolved parameter path, so naming `user`
 * skips `user[name]` too. A configured `foo[]` matches any subscript, which is
 * how a whole repeated field is skipped without naming every index.
 */
final readonly class SkipList
{
    private function __construct(private ?string $pattern) {}

    /**
     * Builds the list from the `skip` configuration value, which config may
     * hand over as an array, a ParameterHolder, or nothing at all.
     */
    public static function fromConfig(mixed $skip): self
    {
        if ($skip instanceof ParameterHolder) {
            $skip = $skip->getParameters();
        }

        if (!is_array($skip) || $skip === []) {
            return new self(null);
        }

        $names = [];
        foreach ($skip as $value) {
            if (is_scalar($value)) {
                $names[] = preg_quote((string) $value, '/');
            }
        }

        if ($names === []) {
            return new self(null);
        }

        // \[\] becomes "any subscript": a configured foo[] skips foo[0], foo[bar] and so on.
        $alternatives = str_replace('\[\]', '\[[^\]]*\]', implode('|\A', $names));

        return new self('/(\A' . $alternatives . ')/');
    }

    public function skips(string $parameterPath): bool
    {
        return $this->pattern !== null && preg_match($this->pattern, $parameterPath) === 1;
    }

    /** Whether anything is configured at all, so callers can skip the check entirely. */
    public function isEmpty(): bool
    {
        return $this->pattern === null;
    }
}
