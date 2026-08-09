<?php

declare(strict_types=1);

namespace Quiote\Docs\Scan;

use Composer\Autoload\ClassLoader;
use Quiote\Support\Compiler\Diagnostic;

/**
 * Finds every documentable class-like in the framework by walking Composer's PSR-4
 * prefix map and reading each file's own declaration.
 *
 * The prefix map is the discovery source rather than a classmap or a fixed directory
 * list because the framework is one namespace spread across many packages -- adding a
 * package must not mean editing this class. Nothing here autoloads: a candidate is
 * accepted only when the name its file declares matches the name its path implies, so
 * a path that maps to no real class is skipped rather than handed to `class_exists()`,
 * where Composer would include the file again and PHP would raise an uncatchable
 * redeclaration fatal.
 */
final class SourceScanner
{
    private const ROOT_NAMESPACE = 'Quiote\\';

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /** @var list<array{prefix: string, baseDir: string}>|null */
    private ?array $roots = null;

    /**
     * @param bool $excludeTestDirectories Whether to drop base directories under a `tests` segment.
     *                                     On by default because `autoload-dev` prefixes share the
     *                                     PSR-4 map with the real ones; only this package's own
     *                                     fixtures, which deliberately live under `tests`, turn it
     *                                     off.
     */
    public function __construct(
        private readonly ?ClassLoader $loader = null,
        private readonly FileTokenReader $reader = new FileTokenReader(),
        private readonly bool $excludeTestDirectories = true,
    ) {
    }

    /**
     * Returns every class-like under the framework's namespace, ordered by name.
     *
     * @return list<ScannedType>
     */
    public function scan(): array
    {
        $this->diagnostics = [];
        $found = [];
        $resolved = [];
        $unmatched = [];

        foreach ($this->roots() as ['prefix' => $prefix, 'baseDir' => $baseDir]) {
            foreach ($this->phpFilesIn($baseDir) as $path) {
                $types = $this->readFile($path, $prefix, $baseDir);

                if ($types === null) {
                    // The path implied a name this file does not declare. That is normal when one
                    // base directory sits under two prefixes: the file resolves under the other
                    // one. Hold the complaint until every prefix has had its turn.
                    $unmatched[$path] ??= $prefix;
                    continue;
                }

                $resolved[$path] = true;
                foreach ($types as $scanned) {
                    // The same file reached through two prefixes yields identical declarations.
                    $found[$scanned->fqcn] ??= $scanned;
                }
            }
        }

        foreach ($unmatched as $path => $prefix) {
            if (isset($resolved[$path])) {
                continue;
            }

            $this->diagnostics[] = new Diagnostic(
                Diagnostic::SEVERITY_WARNING,
                Diagnostic::CODE_UNRESOLVABLE_CLASS,
                sprintf(
                    'No PSR-4 prefix addresses what this file declares; the "%s" prefix implies a '
                    . 'name it does not define, so it is skipped rather than reflected -- loading '
                    . 'the implied name would include the file a second time.',
                    $prefix,
                ),
                $path,
            );
        }

        ksort($found, SORT_STRING);

        return array_values($found);
    }

    /**
     * Diagnostics accumulated by the last {@see scan()}: files whose declaration did not
     * match the path that found them, and files declaring nothing at all.
     *
     * @return list<Diagnostic>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Reads one file, returning every class-like it declares, or null when the path implies
     * a name the file does not define.
     *
     * The path-implied name has to match one of the declarations for the file to be safe to
     * reflect. Once it does, the rest are safe too: loading the file defines all of them at
     * once, so a companion type declared beside the addressable one -- the way
     * `Quiote\DI\Container` ships its PSR-11 exceptions -- is reachable and documentable.
     *
     * @return list<ScannedType>|null
     */
    private function readFile(string $path, string $prefix, string $baseDir): ?array
    {
        $declaration = $this->reader->read($path);
        if ($declaration === null) {
            // Tombstones and side-effect-only scripts live alongside real classes; neither is
            // a defect, so neither is worth a diagnostic. Report the file as resolved so no
            // later prefix complains about it.
            return [];
        }

        $expected = $this->fqcnFor($path, $prefix, $baseDir);
        $namespace = $declaration['namespace'];

        $names = [];
        foreach ($declaration['types'] as $type) {
            $names[$type['name']] = $namespace !== ''
                ? $namespace . '\\' . $type['name']
                : $type['name'];
        }

        if (!in_array($expected, $names, true)) {
            return null;
        }

        $scanned = [];
        foreach ($declaration['types'] as $type) {
            $scanned[] = new ScannedType(
                fqcn: $names[$type['name']],
                shortName: $type['name'],
                namespace: $namespace,
                kind: $type['kind'],
                absolutePath: $path,
                baseDir: $baseDir,
                imports: $declaration['imports'],
            );
        }

        return $scanned;
    }

    private function fqcnFor(string $path, string $prefix, string $baseDir): string
    {
        $relative = substr($path, strlen(rtrim($baseDir, '/')) + 1);

        return $prefix . str_replace('/', '\\', substr($relative, 0, -4));
    }

    /**
     * The prefix/directory pairs that contribute framework code.
     *
     * A pair rather than a map, because neither side is unique: one prefix may list several
     * directories, and one directory may sit under several prefixes -- `packages/session-pdo/src`
     * is reachable as both `Quiote\Session\Pdo\` and `Quiote\Storage\Pdo\`, and only the first
     * names anything the files actually declare. Collapsing either side would silently drop real
     * classes, so every pair is tried and the declarations decide.
     *
     * Pairs are ordered longest prefix first, so a package wins over the kernel for a namespace
     * they share, and by name within a length, so the result does not depend on the order Composer
     * happened to install things in. Directories are resolved to their real path because the
     * monorepo installs its own packages as symlinks, and any directory under a `tests` segment is
     * dropped -- by path, not by namespace, since `Quiote\Testing` is shipped API.
     *
     * @return list<array{prefix: string, baseDir: string}>
     */
    public function roots(): array
    {
        if ($this->roots !== null) {
            return $this->roots;
        }

        $loader = $this->loader ?? $this->composerLoader();
        if ($loader === null) {
            return $this->roots = [];
        }

        $roots = [];
        $seen = [];

        foreach ($loader->getPrefixesPsr4() as $prefix => $dirs) {
            if (!str_starts_with($prefix, self::ROOT_NAMESPACE)) {
                continue;
            }

            foreach ($dirs as $dir) {
                $real = realpath($dir);
                if ($real === false || !is_dir($real)) {
                    continue;
                }
                if ($this->excludeTestDirectories && $this->isTestPath($real)) {
                    continue;
                }
                // Only an exact repeat of the same pair is redundant.
                $key = $prefix . '|' . $real;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $roots[] = ['prefix' => $prefix, 'baseDir' => $real];
            }
        }

        usort($roots, static fn(array $a, array $b): int
            => (strlen($b['prefix']) <=> strlen($a['prefix']))
                ?: (strcmp($a['prefix'], $b['prefix'])
                    ?: strcmp($a['baseDir'], $b['baseDir'])));

        return $this->roots = $roots;
    }

    private function isTestPath(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === 'tests' || $segment === 'test') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $baseDir): array
    {
        $paths = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $paths[] = $file->getPathname();
        }

        // Directory iteration follows the filesystem's own order, which differs between
        // filesystems and machines; the emitted artifacts must not.
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function composerLoader(): ?ClassLoader
    {
        foreach (spl_autoload_functions() ?: [] as $callable) {
            if (is_array($callable) && $callable[0] instanceof ClassLoader) {
                return $callable[0];
            }
        }

        return null;
    }
}
