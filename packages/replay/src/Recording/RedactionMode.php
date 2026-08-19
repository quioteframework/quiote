<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use RuntimeException;

/** How {@see Redactor} replaces a value matched against a denylist. */
enum RedactionMode: string
{
    case Drop = 'drop';
    case Hash = 'hash';
    case Mask = 'mask';

    /** An unrecognised value throws rather than silently falling back to `drop`. */
    public static function fromConfigValue(string $value): self
    {
        $mode = self::tryFrom($value);
        if ($mode === null) {
            throw new RuntimeException(sprintf(
                'replay.redact.mode is "%s"; expected one of: %s.',
                $value,
                implode(', ', array_map(static fn(self $case): string => $case->value, self::cases())),
            ));
        }

        return $mode;
    }
}
