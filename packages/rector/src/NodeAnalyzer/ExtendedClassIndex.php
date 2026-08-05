<?php

declare(strict_types=1);

namespace Quiote\Rector\NodeAnalyzer;

use Composer\Autoload\ClassLoader;

/**
 * Whether anything in the codebase extends a given class.
 *
 * Adding a constructor parameter to a class that others extend is not safe, and a dry run against the
 * reference application's `Api` module is what proved it. `ApiBaseAction` is concrete, has 58
 * subclasses, and six of them already declared their own constructor. Injecting into the base gave it
 * `__construct(RequestState, User)`; no subclass forwards to it, so every one of them would have left
 * `$this->user` uninitialized -- and `ApiBaseAction::validatePermissions()`, which every API action
 * calls, reads exactly that. A whole module fatalling at runtime, produced by a migration tool, in
 * code that had just been reviewed.
 *
 * The reverse is no better: appending a required parameter to a base class's *existing* constructor
 * breaks every subclass that forwards with the old arity. And which of the two happens depends on the
 * order Rector reaches the files in, so it is not even deterministic.
 *
 * Rector 2.6 has no subclass enumeration -- `FamilyRelationsAnalyzer` walks ancestors, not
 * descendants -- so this indexes the codebase itself, once per process.
 *
 * ## Deliberately approximate, in the safe direction
 *
 * Matching is on the **short name** after `extends`, not on a resolved fully-qualified name. Resolving
 * one properly means tracking imports and aliases per file, which is where a scanner like this gets
 * subtly wrong. A short-name match can therefore report "extended" for a same-named class in an
 * unrelated namespace -- and the consequence of that is a rule declining a site it could have
 * rewritten, which lands in the residue report for a human to look at. The consequence of the
 * opposite error is the module-wide fatal described above. So the approximation only ever costs
 * coverage, never correctness.
 *
 * @since      4.0.0
 */
final class ExtendedClassIndex
{
    /**
     * Short names that appear after `extends` anywhere in the scanned roots.
     *
     * @var        ?array<string, true>
     */
    private ?array $extendedShortNames = null;

    /**
     * @param      ?array<int, string> $roots Directories to scan. Null asks Composer, which is what
     *                    the container does; a caller that knows its own source roots -- a test --
     *                    passes them instead of arranging an autoloader to be discovered.
     */
    public function __construct(private readonly ?array $roots = null) {}

    /**
     * Whether any class in the codebase extends this one.
     *
     * @since      4.0.0
     */
    public function isExtended(string $className): bool
    {
        $shortName = str_contains($className, '\\')
            ? substr($className, strrpos($className, '\\') + 1)
            : $className;

        return isset($this->index()[$shortName]);
    }

    /**
     * @return     array<string, true>
     * @since      4.0.0
     */
    private function index(): array
    {
        if ($this->extendedShortNames !== null) {
            return $this->extendedShortNames;
        }

        $found = [];

        foreach ($this->roots ?? $this->composerSourceRoots() as $root) {
            foreach ($this->extendedNamesIn($root) as $shortName) {
                $found[$shortName] = true;
            }
        }

        return $this->extendedShortNames = $found;
    }

    /**
     * The directories to scan: every PSR-4 root every registered Composer autoloader knows, which
     * covers the framework and -- when an application's autoloader is passed with
     * `--autoload-file` -- the application too.
     *
     * `vendor/` roots are skipped. A framework or library class being extended by its own package is
     * not what this guard is about, and scanning a full vendor tree per run is not worth the seconds.
     *
     * @return     array<int, string>
     * @since      4.0.0
     */
    private function composerSourceRoots(): array
    {
        $roots = [];

        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (!is_array($autoloader)) {
                continue;
            }

            $loader = array_shift($autoloader);
            if (!$loader instanceof ClassLoader) {
                continue;
            }

            foreach ($loader->getPrefixesPsr4() as $dirs) {
                foreach ($dirs as $dir) {
                    $real = realpath($dir);
                    if ($real === false || str_contains($real, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                        continue;
                    }
                    $roots[$real] = true;
                }
            }
        }

        return array_keys($roots);
    }

    /**
     * Record every short name this root's PHP files extend.
     *
     * Read with a regex rather than parsed: this runs over the whole application tree and only needs
     * the name after `extends`, so a parse of every file would cost far more than the answer is worth.
     *
     * @return     array<int, string>
     * @since      4.0.0
     */
    private function extendedNamesIn(string $root): array
    {
        $names = [];

        if (!is_dir($root)) {
            return $names;
        }

        $directoryIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($directoryIterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());
            if ($contents === false || !str_contains($contents, 'extends')) {
                continue;
            }

            if (preg_match_all('/\bextends\s+\\\\?([A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[\w\x80-\xff]+)*)/', $contents, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $parentName) {
                $names[] = str_contains($parentName, '\\')
                    ? substr($parentName, strrpos($parentName, '\\') + 1)
                    : $parentName;
            }
        }

        return $names;
    }
}
