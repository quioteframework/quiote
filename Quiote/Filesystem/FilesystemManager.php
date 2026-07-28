<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

use Quiote\DI\Container;
use RuntimeException;

/**
 * App-facing entry point: `$container->get(FilesystemManager::class)->write('reports/x.csv', $csv)`.
 * Resolves the configured driver (or an explicit alias) from
 * {@see FilesystemDriverRegistry} via {@see Container::get()} — a driver is a
 * long-lived service (memoized like any other), not constructed per call.
 * Mirrors {@see \Quiote\Queue\QueueManager} exactly.
 */
final readonly class FilesystemManager
{
    public function __construct(
        private Container $container,
        private FilesystemConfig $config,
    ) {
    }

    public function disk(?string $alias = null): FilesystemAdapterInterface
    {
        $class = FilesystemDriverRegistry::instantiateClassFor($alias ?? $this->config->defaultDisk);

        $adapter = $this->container->get($class);
        if (!$adapter instanceof FilesystemAdapterInterface) {
            throw new RuntimeException(sprintf(
                'Filesystem driver class "%s" must implement %s, got %s.',
                $class,
                FilesystemAdapterInterface::class,
                get_debug_type($adapter),
            ));
        }

        return $adapter;
    }

    public function read(string $path): string
    {
        return $this->disk()->read($path);
    }

    public function write(string $path, string $contents): void
    {
        $this->disk()->write($path, $contents);
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }
}
