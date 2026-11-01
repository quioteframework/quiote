<?php

declare(strict_types=1);

namespace Quiote\Error;

use Throwable;

/**
 * An exception the application caught and answered itself, as reported through
 * {@see ErrorReporting::handled()}, with the caller's note on what it did about it.
 */
final readonly class HandledException
{
    public function __construct(
        public Throwable $exception,
        public ?string $note = null,
    ) {
    }
}
