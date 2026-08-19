<?php

declare(strict_types=1);

namespace Quiote\Replay\Store;

use FilesystemIterator;
use Quiote\Config\Config;
use Quiote\Exception\StorageException;
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
     * @throws StorageException if the directory is empty, sits inside the app's public
     *         document root, or cannot be created/written to.
     */
    public function __construct(
        string $directory,
        private readonly CassetteCodec $codec = new CassetteCodec(),
    ) {
        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            throw new StorageException('Cassette directory must not be empty.');
        }
        if (self::isInsidePublicDocumentRoot($directory)) {
            throw new StorageException(sprintf(
                'Cassette directory "%s" must not be inside the application\'s public document root ("%s"): '
                . 'a cassette can carry request bodies and session data and must never be web-servable.',
                $directory,
                rtrim(Config::getString('core.app_dir', ''), '/\\') . '/pub',
            ));
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException(sprintf('Cassette directory "%s" could not be created.', $directory));
        }
        if (!is_writable($directory)) {
            throw new StorageException(sprintf('Cassette directory "%s" is not writable.', $directory));
        }
        $this->directory = $directory;
    }

    public function put(CassetteId $id, Cassette $cassette): void
    {
        $payload = $this->codec->encode($cassette);
        $file = $this->fileFor($id);
        $tmp = $this->directory . DIRECTORY_SEPARATOR . uniqid('.tmp-', true);

        if (@file_put_contents($tmp, $payload) !== strlen($payload)) {
            @unlink($tmp);
            throw new StorageException(sprintf('Failed writing cassette file in "%s".', $this->directory));
        }
        @chmod($tmp, 0600);
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
            $normalizedPublic = rtrim($publicDir, '/\\');

            return str_starts_with(rtrim($resolvedDirectory, '/\\'), $normalizedPublic);
        }

        return str_starts_with(rtrim($resolvedDirectory, '/\\'), rtrim($resolvedPublic, '/\\'));
    }
}
