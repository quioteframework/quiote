<?php

declare(strict_types=1);

namespace Quiote\Support\Environment;

/**
 * The real environment: `getenv()` first, then `$_ENV`.
 *
 * `getenv()` alone is not the whole environment a modern app runs in. A
 * dotenv loader -- vlucas/phpdotenv's default `createImmutable()`, for one --
 * populates `$_ENV` and `$_SERVER` and deliberately does *not* call
 * `putenv()`, because `putenv()`/`getenv()` mutate process-global state that
 * is not thread-safe. A reader that only asked `getenv()` would therefore
 * answer "unset" for every variable such a bootstrap loaded, while answering
 * correctly for the same variable set by a container runtime. Asking both
 * makes the two indistinguishable to a caller, which is what they are to the
 * deployment.
 *
 * A real process variable wins over `$_ENV`: it is the one the platform set,
 * and an immutable dotenv repository will not have overwritten it anyway.
 * `$_SERVER` is deliberately not consulted even though dotenv writes there
 * too -- under CGI and FastCGI it also carries the request (`HTTP_*`,
 * `REQUEST_URI`, `QUERY_STRING`), and configuration must not be able to reach
 * that. Anything dotenv puts in `$_SERVER` it puts in `$_ENV` as well.
 *
 * Only a string in `$_ENV` counts: the superglobal mirrors an environment
 * whose values are strings, and a caller has an interface promising
 * `string|false` to honour. This is what the container binds
 * {@see EnvironmentReaderInterface} to by default; nothing here is mockable,
 * which is the point -- tests reach for a fake reader, `putenv()` or `$_ENV`
 * instead of stubbing this class.
 */
final class SystemEnvironmentReader implements EnvironmentReaderInterface
{
    public function get(string $name): string|false
    {
        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }

        $fallback = $_ENV[$name] ?? null;

        return is_string($fallback) ? $fallback : false;
    }
}
