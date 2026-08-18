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

    /**
     * Resolves a disk by driver alias, defaulting to the configured `filesystem.default_disk`.
     *
     * The alias is mapped to a class through {@see FilesystemDriverRegistry} and the instance comes
     * from the container, so a driver registered as a singleton is shared across calls rather than
     * rebuilt per operation.
     *
     * @throws     RuntimeException If the alias is unknown, or the class the container returned
     *             does not implement {@see FilesystemAdapterInterface}.
     */
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

    /** Reads $path from the default disk. */
    public function read(string $path): string
    {
        return $this->disk()->read($path);
    }

    /** Writes $contents to $path on the default disk. */
    public function write(string $path, string $contents): void
    {
        $this->disk()->write($path, $contents);
    }

    /** Deletes $path from the default disk. */
    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /** Reports whether $path exists on the default disk. */
    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    /**
     * The configured disk, narrowed to one that can enumerate its contents.
     *
     * Not every store can: the object-store drivers are built on single-object calls with no list
     * endpoint. Asking here rather than discovering it from a thrown exception means the failure
     * names the disk that cannot do it and the driver behind it.
     *
     * @throws     RuntimeException If the resolved driver is not listable.
     * @since      3.2.0
     */
    public function listableDisk(?string $alias = null): ListableFilesystemInterface
    {
        $adapter = $this->disk($alias);
        if (!$adapter instanceof ListableFilesystemInterface) {
            throw new RuntimeException(sprintf(
                'Filesystem disk "%s" cannot list its contents: driver %s does not implement %s. '
                . 'Its underlying store has no list operation — keep the listing alongside the '
                . 'records that own the files, or use a listable driver such as %s.',
                $alias ?? $this->config->defaultDisk,
                $adapter::class,
                ListableFilesystemInterface::class,
                LocalFilesystemAdapter::class,
            ));
        }

        return $adapter;
    }

    /**
     * @return     list<string> Relative paths directly under $path, non-recursive.
     * @throws     RuntimeException If the configured driver cannot enumerate.
     * @since      3.2.0
     */
    public function listContents(string $path = ''): array
    {
        return $this->listableDisk()->listContents($path);
    }
}
