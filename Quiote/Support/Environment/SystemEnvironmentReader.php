<?php

declare(strict_types=1);

namespace Quiote\Support\Environment;

/**
 * The real environment: {@see get()} answers from `getenv()` exactly as a
 * direct call always did. This is what the container binds
 * {@see EnvironmentReaderInterface} to by default; nothing here is mockable,
 * which is the point -- tests reach for a fake reader or `putenv()` instead
 * of stubbing this class.
 */
final class SystemEnvironmentReader implements EnvironmentReaderInterface
{
    public function get(string $name): string|false
    {
        return getenv($name);
    }
}
