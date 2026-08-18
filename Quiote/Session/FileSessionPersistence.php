<?php

declare(strict_types=1);

namespace Quiote\Session;

use FilesystemIterator;
use Quiote\Exception\StorageException;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;
use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SystemRandomness;
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

    private string $directory;
    private int $idleTtl = 1440;
    private int $gcProbability = 1;
    private int $gcDivisor = 100;

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws StorageException if the directory cannot be created or written to.
     */
    public function __construct(
        string $directory,
        array $parameters = [],
        private readonly SessionCodecInterface $codec = new SessionCodec(preferBinary: true),
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly RandomnessInterface $randomness = new SystemRandomness(),
    ) {
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

    /**
     * Reads a session from its file, decoding it through the configured codec.
     *
     * Returns null when no file exists for the id, when the file is empty or
     * unreadable, or — with a non-zero `idle_ttl` — when its mtime is older than
     * that many seconds, in which case the stale file is unlinked on the way out.
     * A future-dated mtime, which a backward clock step can produce, does not
     * count as expired.
     */
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
            if ($mtime !== false && $this->clock->unixTimestamp() - $mtime > $this->idleTtl) {
                @unlink($file);
                return null;
            }
        }

        $blob = @file_get_contents($file);
        if ($blob === false || $blob === '') {
            return null;
        }
        return $this->codec->decode($blob);
    }

    /**
     * Writes the session atomically: encode, write to a temp file in the same
     * directory, chmod 0600, rename into place.
     *
     * Readers therefore never see a half-written session and no locking is
     * needed; concurrent saves are last-write-wins. After a successful publish
     * this may run {@see gc()}, with probability `gc_probability`/`gc_divisor`.
     *
     * @throws StorageException if the temp file cannot be written or renamed
     *         into place; the temp file is cleaned up first in both cases.
     */
    public function save(string $sid, array $data): void
    {
        $payload = $this->codec->encode($data);
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

        if ($this->gcProbability > 0 && $this->randomness->int(1, $this->gcDivisor) <= $this->gcProbability) {
            $this->gc();
        }
    }

    /**
     * Unlinks the session's file.
     *
     * Best-effort and silent, matching the PDO backend: an unknown id and a
     * failed unlink are both indistinguishable no-ops to the caller.
     */
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
        $cutoff = $this->clock->unixTimestamp() - $this->idleTtl;
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

}
