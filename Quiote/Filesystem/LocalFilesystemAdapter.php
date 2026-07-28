<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

use DateTimeImmutable;
use FilesystemIterator;
use SplFileInfo;
use Throwable;

/**
 * Zero-dependency local-disk {@see FilesystemAdapterInterface} — the default
 * driver. Every path is resolved relative to a fixed root directory; `..`
 * segments and absolute paths are rejected so caller-given paths can never
 * escape the root (unlike {@see \Quiote\Session\FileSessionPersistence},
 * which hashes its keys, a general filesystem API takes paths straight from
 * callers, so this guard is load-bearing here).
 *
 * Writes go to a temp file in the same directory and are renamed into place
 * (same atomic pattern as {@see \Quiote\Session\FileSessionPersistence::save()}),
 * so readers never observe a partially written file.
 */
final class LocalFilesystemAdapter implements FilesystemAdapterInterface
{
    private readonly string $root;

    /** @throws FilesystemStorageException if the root directory cannot be created or written to. */
    public function __construct(string $root)
    {
        $root = rtrim($root, '/\\');
        if ($root === '') {
            throw new FilesystemStorageException('Filesystem root must not be empty.');
        }
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new FilesystemStorageException(sprintf('Filesystem root "%s" could not be created.', $root));
        }
        if (!is_writable($root)) {
            throw new FilesystemStorageException(sprintf('Filesystem root "%s" is not writable.', $root));
        }
        $this->root = $root;
    }

    #[\Override]
    public function read(string $path): string
    {
        $file = $this->resolve($path);
        if (!is_file($file)) {
            throw new FileNotFoundStorageException(sprintf('File "%s" does not exist.', $path));
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new FilesystemStorageException(sprintf('Failed reading file "%s".', $path));
        }
        return $contents;
    }

    #[\Override]
    public function write(string $path, string $contents): void
    {
        $file = $this->resolve($path);
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new FilesystemStorageException(sprintf('Directory for "%s" could not be created.', $path));
        }

        $tmp = $directory . DIRECTORY_SEPARATOR . uniqid('.tmp-', true);
        if (@file_put_contents($tmp, $contents) !== strlen($contents)) {
            @unlink($tmp);
            throw new FilesystemStorageException(sprintf('Failed writing file "%s".', $path));
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new FilesystemStorageException(sprintf('Failed publishing file "%s".', $path));
        }
    }

    #[\Override]
    public function delete(string $path): void
    {
        @unlink($this->resolve($path));
    }

    #[\Override]
    public function exists(string $path): bool
    {
        return is_file($this->resolve($path));
    }

    #[\Override]
    public function size(string $path): int
    {
        $file = $this->resolve($path);
        if (!is_file($file)) {
            throw new FileNotFoundStorageException(sprintf('File "%s" does not exist.', $path));
        }
        $size = @filesize($file);
        if ($size === false) {
            throw new FilesystemStorageException(sprintf('Failed reading size of file "%s".', $path));
        }
        return $size;
    }

    #[\Override]
    public function lastModified(string $path): DateTimeImmutable
    {
        $file = $this->resolve($path);
        if (!is_file($file)) {
            throw new FileNotFoundStorageException(sprintf('File "%s" does not exist.', $path));
        }
        $mtime = @filemtime($file);
        if ($mtime === false) {
            throw new FilesystemStorageException(sprintf('Failed reading mtime of file "%s".', $path));
        }
        return (new DateTimeImmutable())->setTimestamp($mtime);
    }

    #[\Override]
    public function listContents(string $path = ''): array
    {
        $directory = $path === '' ? $this->root : $this->resolve($path);
        if (!is_dir($directory)) {
            return [];
        }

        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (Throwable $e) {
            throw new FilesystemStorageException(sprintf('Directory "%s" is not readable: %s', $path, $e->getMessage()), 0, $e);
        }

        $entries = [];
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $relative = ltrim(substr($entry->getPathname(), strlen($this->root)), '/\\');
            $entries[] = $relative;
        }
        sort($entries);

        return $entries;
    }

    /** @throws FilesystemStorageException if $path attempts to escape the root. */
    private function resolve(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '' || $normalized[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
            throw new FilesystemStorageException(sprintf('Path "%s" is not a valid relative path.', $path));
        }

        return $this->root . DIRECTORY_SEPARATOR . $normalized;
    }
}
