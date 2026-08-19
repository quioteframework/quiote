<?php

declare(strict_types=1);

namespace Quiote\Replay\Store;

use FilesystemIterator;
use Quiote\Config\Config;
use Quiote\Exception\StorageException;
use Quiote\Logging\Log;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;

/**
 * Development-default store: never the right choice in production (an AKS
 * pod's filesystem disappears on restart/eviction/scale-down), but a
 * zero-dependency default that makes the feature usable immediately.
 *
 * Modeled on {@see \Quiote\Session\FileSessionPersistence}'s pattern:
 * directory created `0700` at construction (refusing to proceed rather than
 * degrade permissions later), each write goes to a temp file in the same
 * directory, `chmod 0600`, then renamed into place, so a reader never
 * observes a partially written cassette.
 */
final class FileCassetteStore implements ListableCassetteStoreInterface
{
    private const FILE_SUFFIX = '.qcast';

    private readonly string $directory;

    /**
     * @throws StorageException if the directory is empty, is relative with no `core.app_dir` to
     *         anchor it, sits inside the app's public document root, cannot be created/written to,
     *         or already exists with permissions wider than the owner.
     */
    public function __construct(
        string $directory,
        private readonly CassetteCodec $codec = new CassetteCodec(),
    ) {
        $directory = self::anchored(rtrim($directory, '/\\'));
        if (self::isInsidePublicDocumentRoot($directory)) {
            throw new StorageException(sprintf(
                'Cassette directory "%s" must not be inside the application\'s public document root ("%s"): '
                . 'a cassette can carry request bodies and session data and must never be web-servable.',
                $directory,
                rtrim(Config::getString('core.app_dir', ''), '/\\') . '/pub',
            ));
        }
        $existed = is_dir($directory);
        if (!$existed && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException(sprintf('Cassette directory "%s" could not be created.', $directory));
        }
        if (!is_writable($directory)) {
            throw new StorageException(sprintf('Cassette directory "%s" is not writable.', $directory));
        }
        if ($existed) {
            self::tightenToOwnerOnly($directory);
        }
        $this->directory = $directory;
    }

    public function put(CassetteId $id, Cassette $cassette): void
    {
        $payload = $this->codec->encode($cassette);
        $file = $this->fileFor($id);
        $tmp = $this->directory . DIRECTORY_SEPARATOR . uniqid('.tmp-', true);

        // Created 0600 *before* anything is written to it, rather than chmod'd afterwards: a
        // cassette carries request bodies and session data, and between the write and the chmod the
        // file carried whatever the process umask allowed.
        $handle = @fopen($tmp, 'x');
        if ($handle === false) {
            throw new StorageException(sprintf('Failed creating cassette temp file in "%s".', $this->directory));
        }
        @chmod($tmp, 0600);
        $written = @fwrite($handle, $payload);
        @fclose($handle);

        if ($written !== strlen($payload)) {
            @unlink($tmp);
            throw new StorageException(sprintf('Failed writing cassette file in "%s".', $this->directory));
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StorageException(sprintf('Failed publishing cassette file "%s".', $file));
        }
    }

    public function get(CassetteId $id): ?Cassette
    {
        $file = $this->fileFor($id);
        if (!is_file($file)) {
            return null;
        }
        $blob = @file_get_contents($file);
        if ($blob === false || $blob === '') {
            return null;
        }

        return $this->codec->decode($blob);
    }

    public function has(CassetteId $id): bool
    {
        return is_file($this->fileFor($id));
    }

    public function delete(CassetteId $id): void
    {
        @unlink($this->fileFor($id));
    }

    /**
     * Every cassette id currently in the store, for `cassette:list` -- the
     * file store's stand-in for a real object-store `listObjects()`.
     *
     * @return list<string> slugs, not raw ids -- the file store never learns a cassette's
     *         raw id without decoding it.
     */
    public function slugs(): array
    {
        $slugs = [];
        try {
            $iterator = new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS);
        } catch (\Throwable $e) {
            throw new StorageException(sprintf('Cassette directory "%s" is not readable: %s', $this->directory, $e->getMessage()), 0, $e);
        }
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }
            $name = $entry->getFilename();
            if (str_ends_with($name, self::FILE_SUFFIX)) {
                $slugs[] = substr($name, 0, -strlen(self::FILE_SUFFIX));
            }
        }
        sort($slugs);

        return $slugs;
    }

    private function fileFor(CassetteId $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id->slug . self::FILE_SUFFIX;
    }

    /**
     * Whether $path is $ancestor itself or sits beneath it, matched on whole path segments.
     *
     * A bare `str_starts_with` treats `/app/pubfoo` as inside `/app/pub`. That fails closed, so it
     * only ever produced a spurious refusal rather than an exposure -- but a store refusing a
     * perfectly private directory is its own problem.
     */
    private static function isAtOrUnder(string $path, string $ancestor): bool
    {
        return $path === $ancestor || str_starts_with($path, $ancestor . '/') || str_starts_with($path, $ancestor . '\\');
    }

    /**
     * Resolves a relative path against `core.app_dir`, and refuses one when there is no app dir to
     * resolve it against.
     *
     * `replay.store.path` and `replay.local_path` both default to the relative `var/cassettes`, and
     * nothing anchored it -- so where cassettes containing request bodies and session data actually
     * landed depended on the process working directory: the project root under RoadRunner or
     * Swoole, frequently the document root under php-fpm. The public-root check below only knows
     * one shape of web root, so a deployment whose real root is elsewhere got no protection at all.
     * Refusing an unanchorable relative path is the honest answer: a cassette store whose location
     * is decided by the CWD is not a location anyone chose.
     */
    private static function anchored(string $directory): string
    {
        if ($directory === '') {
            throw new StorageException('Cassette directory must not be empty.');
        }
        if (self::isAbsolute($directory)) {
            return $directory;
        }

        $appDir = rtrim(Config::getString('core.app_dir', ''), '/\\');
        if ($appDir === '') {
            throw new StorageException(sprintf(
                'Cassette directory "%s" is relative and "core.app_dir" is not set, so where cassettes would '
                . 'be written depends on the process working directory -- which differs between php-fpm, '
                . 'RoadRunner and the console. Configure an absolute path, or set core.app_dir.',
                $directory,
            ));
        }

        return $appDir . DIRECTORY_SEPARATOR . $directory;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * Narrows a pre-existing directory to `0700`, and refuses it only if that cannot be done.
     *
     * The `0700` on the `mkdir()` above applies only to a directory this class creates. One that
     * already existed -- `mkdir -p var/cassettes` under the usual umask leaves 0755, or an earlier
     * deployment left it behind -- was accepted with whatever mode it had, so the 0600 cassettes
     * inside it sat in a directory anyone on the host could list and traverse.
     *
     * Tightened rather than rejected. Rejecting is the stricter-sounding choice and the worse one:
     * this store is a container singleton the recorder middleware resolves, so throwing here takes
     * every request down -- turning a permissions problem into an outage, on a directory shape
     * common enough to be the default. Narrowing fixes the exposure instead, and says so, because
     * silently changing a directory's permissions is not something to do quietly.
     *
     * Skipped on Windows, where the POSIX bits `stat()` reports are not the real ACL.
     */
    private static function tightenToOwnerOnly(string $directory): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $mode = @fileperms($directory);
        if ($mode === false || ($mode & 0o077) === 0) {
            return;
        }

        @chmod($directory, 0700);
        clearstatcache(true, $directory);
        $narrowed = @fileperms($directory) ?: 0;

        if (($narrowed & 0o077) !== 0) {
            throw new StorageException(sprintf(
                'Cassette directory "%s" is mode %04o and could not be narrowed to 0700: group or other can '
                . 'reach it. A cassette can carry request bodies, cookies and session data, so this must not '
                . 'be readable by anyone but the owner. Fix the ownership, or point replay.store.path '
                . 'somewhere private.',
                $directory,
                $narrowed & 0o777,
            ));
        }

        Log::create(self::class)->warning(sprintf(
            'Narrowed cassette directory "%s" from mode %04o to 0700: it was reachable by group or other, and '
            . 'a cassette can carry request bodies, cookies and session data.',
            $directory,
            $mode & 0o777,
        ));
    }

    /**
     * A narrow, honest check: this framework's only document-root convention is the scaffolded
     * `{core.app_dir}/pub` (see `AppWriter::frontControllerPhp()`), so that is what's checked
     * against -- not a claim that every deployment's real web root is detected. The 0700/0600
     * permissions above are the primary defense; this is a second line against the specific,
     * common mistake of pointing `replay.store.path` at the app's own `pub/`.
     */
    private static function isInsidePublicDocumentRoot(string $directory): bool
    {
        $appDir = Config::getNullableString('core.app_dir');
        if ($appDir === null || $appDir === '') {
            return false;
        }
        $publicDir = rtrim($appDir, '/\\') . '/pub';
        $resolvedPublic = realpath($publicDir);
        $resolvedDirectory = realpath($directory) ?: $directory;
        if ($resolvedPublic === false) {
            // The public dir doesn't exist (yet) on disk -- compare by prefix instead of realpath,
            // so a not-yet-created cassette directory nested under a not-yet-created pub/ is still
            // caught rather than silently allowed through realpath() returning false.
            return self::isAtOrUnder(rtrim($resolvedDirectory, '/\\'), rtrim($publicDir, '/\\'));
        }

        return self::isAtOrUnder(rtrim($resolvedDirectory, '/\\'), rtrim($resolvedPublic, '/\\'));
    }
}
