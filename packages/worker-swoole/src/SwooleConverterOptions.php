<?php

declare(strict_types=1);

namespace Quiote\Runtime\Swoole;

/**
 * The handful of things a Swoole request cannot tell us and the server operator
 * has to.
 */
final readonly class SwooleConverterOptions
{
    /**
     * @param string $scriptName Swoole has no front-controller script, but
     *        Routing reads $_SERVER['SCRIPT_NAME'] when generating URLs, so a
     *        plausible value has to be synthesised.
     * @param bool $https Whether Swoole itself is terminating TLS. Not inferred:
     *        behind a TLS-terminating proxy this is false and the X-Forwarded-*
     *        correction in WorkerRequestFactory is what makes the request https.
     */
    public function __construct(
        public string $scriptName = '/index.php',
        public bool $https = false,
    ) {
    }
}
