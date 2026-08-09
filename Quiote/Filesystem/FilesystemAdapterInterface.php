<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

use DateTimeImmutable;

/**
 * A general-purpose "read/write/list a file" contract, distinct from
 * {@see \Quiote\Session\SessionPersistenceInterface} (session-shaped) and the
 * legacy {@see \Quiote\Storage\Storage} hierarchy (`SessionHandlerInterface`-
 * bound). Implementations are registered by alias in
 * {@see FilesystemDriverRegistry} and resolved through {@see FilesystemManager}.
 *
 * Deliberately out of scope for v1: visibility/ACLs, mime-type detection,
 * streaming read/write, directory-as-first-class-object semantics beyond what
 * a driver needs internally, copy/move, checksums/ETags.
 *
 * Enumeration is not here: a store built on single-object calls cannot offer it, so it lives on
 * {@see ListableFilesystemInterface} and a driver opts in by implementing that instead.
 * {@see size()} and {@see lastModified()} are supported by every shipped driver, though a cloud
 * provider that omits the corresponding response header makes them fail at runtime.
 */
interface FilesystemAdapterInterface
{
    /** @throws FileNotFoundStorageException if $path does not exist. */
    public function read(string $path): string;

    /**
     * Stores $contents at $path, replacing whatever was there.
     *
     * Implementations create whatever container the path implies (a parent directory, a key
     * prefix) rather than requiring the caller to prepare it, and a reader must never observe a
     * half-written file.
     */
    public function write(string $path, string $contents): void;

    /** Best-effort: a no-op if $path does not exist. */
    public function delete(string $path): void;

    /** Reports whether a file is stored at $path. Directories and prefixes do not count as files. */
    public function exists(string $path): bool;

    /** @throws FileNotFoundStorageException if $path does not exist. */
    public function size(string $path): int;

    /** @throws FileNotFoundStorageException if $path does not exist. */
    public function lastModified(string $path): DateTimeImmutable;
}
