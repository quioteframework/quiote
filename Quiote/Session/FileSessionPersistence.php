<?php

declare(strict_types=1);

namespace Quiote\Session;

use FilesystemIterator;
use Quiote\Exception\StorageException;
use Throwable;

/**
 * File-backed SessionPersistenceInterface implementation — the zero-dependency
 * default backend. Each session lives in its own file under the configured
 * directory; the filename is the SHA-256 of the session id, so untrusted cookie
 * values can never traverse outside the directory and session ids are not
 * recoverable from a directory listing.
 *
 * Writes go to a temp file in the same directory and are renamed into place, so
 * readers never observe a partially written session and no file locking is
 * needed (a reader holding the old inode keeps a consistent snapshot; the last
 * concurrent save wins, matching the upsert semantics of the PDO backend).
 *
 * Expiry is mtime-based: a file older than `idle_ttl` seconds is treated as
 * unknown on load (and removed). Expired files are additionally swept by gc(),
 * which save() triggers probabilistically (`gc_probability`/`gc_divisor`,
 * defaults 1/100); set `gc_probability` to 0 and call gc() from a cron/queue
 * job to take GC off the request path entirely. An `idle_ttl` of 0 disables
 * expiry (sessions live until deleted).
 *
 * This class deliberately does not touch ext/session (no session_start(),
 * no $_SESSION, no save handlers) — see SessionManager.
 */
class FileSessionPersistence implements SessionPersistenceInterface
{
    private const FILE_SUFFIX = '.sess';
    private const IGBINARY_HEADER = "\x00\x00\x00\x02";

    private string $directory;
    private int $idleTtl = 1440;
    private int $gcProbability = 1;
    private int $gcDivisor = 100;

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws StorageException if the directory cannot be created or written to.
     */
    public function __construct(string $directory, array $parameters = [])
    {
        if (isset($parameters['idle_ttl']) && (is_int($parameters['idle_ttl']) || is_string($parameters['idle_ttl']))) {
            $this->idleTtl = max(0, (int)$parameters['idle_ttl']);
        }
        if (isset($parameters['gc_probability']) && (is_int($parameters['gc_probability']) || is_string($parameters['gc_probability']))) {
            $this->gcProbability = max(0, (int)$parameters['gc_probability']);
        }
        if (isset($parameters['gc_divisor']) && (is_int($parameters['gc_divisor']) || is_string($parameters['gc_divisor']))) {
            $this->gcDivisor = max(1, (int)$parameters['gc_divisor']);
        }

        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            throw new StorageException('Session directory must not be empty.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException(sprintf('Session directory "%s" could not be created.', $directory));
        }
        if (!is_writable($directory)) {
            throw new StorageException(sprintf('Session directory "%s" is not writable.', $directory));
        }
        $this->directory = $directory;
    }

    public function load(string $sid): ?array
    {
        $file = $this->fileFor($sid);
        if (!is_file($file)) {
            return null;
        }

        if ($this->idleTtl > 0) {
            $mtime = @filemtime($file);
            // A backward wall-clock step can make a fresh file look future-dated;
            // only a genuinely stale mtime counts as expired.
            if ($mtime !== false && time() - $mtime > $this->idleTtl) {
                @unlink($file);
                return null;
            }
        }

        $blob = @file_get_contents($file);
        if ($blob === false || $blob === '') {
            return null;
        }
        return $this->decode($blob);
    }

    public function save(string $sid, array $data): void
    {
        $payload = $this->encode($data);
        $file = $this->fileFor($sid);
        $tmp = $this->directory . DIRECTORY_SEPARATOR . uniqid('.tmp-', true);

        if (@file_put_contents($tmp, $payload) !== strlen($payload)) {
            @unlink($tmp);
            throw new StorageException(sprintf('Failed writing session file in "%s".', $this->directory));
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StorageException(sprintf('Failed publishing session file "%s".', $file));
        }

        if ($this->gcProbability > 0 && random_int(1, $this->gcDivisor) <= $this->gcProbability) {
            $this->gc();
        }
    }

    public function delete(string $sid): void
    {
        // best-effort, matching the PDO backend
        @unlink($this->fileFor($sid));
    }

    /**
     * Remove all expired session files. Safe to call concurrently and from
     * outside the request path (cron/queue job). No-op when idle_ttl is 0.
     *
     * @return int number of files removed.
     */
    public function gc(): int
    {
        if ($this->idleTtl === 0) {
            return 0;
        }
        $removed = 0;
        $cutoff = time() - $this->idleTtl;
        try {
            $iterator = new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS);
        } catch (Throwable $e) {
            throw new StorageException(sprintf('Session directory "%s" is not readable: %s', $this->directory, $e->getMessage()), 0, $e);
        }
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }
            $name = $entry->getFilename();
            $isSession = str_ends_with($name, self::FILE_SUFFIX);
            // Orphaned temp files (a crash between write and rename) age out too.
            if (!$isSession && !str_starts_with($name, '.tmp-')) {
                continue;
            }
            $mtime = $entry->getMTime();
            if ($mtime <= $cutoff && @unlink($entry->getPathname())) {
                ++$removed;
            }
        }
        return $removed;
    }

    private function fileFor(string $sid): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $sid) . self::FILE_SUFFIX;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        if (function_exists('igbinary_serialize')) {
            try {
                $payload = igbinary_serialize($data);
                if (is_string($payload)) {
                    return $payload;
                }
            } catch (Throwable) {
            }
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $blob): ?array
    {
        if (str_starts_with($blob, self::IGBINARY_HEADER) && function_exists('igbinary_unserialize')) {
            try {
                $decoded = @igbinary_unserialize($blob);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    return $decoded;
                }
            } catch (Throwable) {
            }
            return null;
        }
        if (str_starts_with($blob, '{') || str_starts_with($blob, '[')) {
            try {
                $decoded = json_decode($blob, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }
        return null;
    }
}
