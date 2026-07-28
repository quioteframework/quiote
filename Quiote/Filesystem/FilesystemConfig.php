<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

use Quiote\Config\Config;

/**
 * Typed snapshot of the `filesystem.*` settings family.
 * Defaults here are read as fallbacks only — {@see FilesystemPlugin} is what
 * actually publishes them into {@see Config} via `configDefault()`.
 *
 * v1 supports one instance per driver alias — there is no multi-instance
 * config for e.g. two differently-configured S3 buckets under the same
 * `s3` alias.
 */
final readonly class FilesystemConfig
{
    public function __construct(
        public string $defaultDisk,
        public string $localRoot,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            defaultDisk: Config::getString('filesystem.default_disk', 'local'),
            localRoot: Config::getString('filesystem.disks.local.root', 'storage/app'),
        );
    }
}
