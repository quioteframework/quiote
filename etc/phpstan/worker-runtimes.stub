<?php

/**
 * Functions provided by a worker-runtime host rather than by any composer
 * package, so PHPStan can check the runtimes that call them on a machine where
 * the host isn't installed. FrankenPHP defines these only in worker mode.
 */

/**
 * @param callable(): void $callback
 */
function frankenphp_handle_request(callable $callback): bool
{
}

function frankenphp_request_context(): mixed
{
}
