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
 * a driver needs internally, copy/move, checksums/ETags. Some drivers (the
 * cloud adapters) cannot implement {@see size()}, {@see lastModified()}, or
 * {@see listContents()} at all — see their own class docblocks.
 */
interface FilesystemAdapterInterface
{
    /** @throws FileNotFoundStorageException if $path does not exist. */
    public function read(string $path): string;

    public function write(string $path, string $contents): void;

    /** Best-effort: a no-op if $path does not exist. */
    public function delete(string $path): void;

    public function exists(string $path): bool;

    /** @throws FileNotFoundStorageException if $path does not exist. */
    public function size(string $path): int;

    /** @throws FileNotFoundStorageException if $path does not exist. */
    public function lastModified(string $path): DateTimeImmutable;

    /** @return list<string> relative paths, non-recursive. */
    public function listContents(string $path = ''): array;
}
