<?php
declare(strict_types=1);

namespace Quiote\Console;

use Quiote\Exception\QuioteException;

/**
 * Resolves the app directory (+ optionally the environment) for a CLI
 * invocation. Shared between {@see \Quiote\Console\Command\AbstractAppCommand}
 * (which needs it once a command actually executes) and `bin/quiote`'s
 * best-effort pre-bootstrap (which needs it before {@see Application} is even
 * constructed, so plugin-contributed commands can appear in `list`/`--help`
 * without the user running a command first — see
 * docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md's "Command contribution boundary").
 *
 * Precedence:
 *  1. `$appDirOption`/`$envOption` — an explicit `--app-dir`/`--env`.
 *  2. `$QUIOTE_APP_DIR`/`$QUIOTE_ENV`.
 *  3. A `.quiote.json` marker file (`{"app_dir": "...", "env": "..."}`),
 *     found by walking up from the current directory. `app_dir` is resolved
 *     relative to the marker file's own directory (or used as-is if
 *     absolute) — this is the fast, explicit path for a project whose app
 *     isn't a directory ancestor of `$CWD` (e.g. multiple apps in one repo),
 *     and the one that lets a CLI invocation know the app *before* having to
 *     guess anything.
 *  4. An upward search from `$CWD` for the first directory containing
 *     `Config/settings.{php,xml,yaml,yml}` — the original, guess-based
 *     fallback, kept for apps with no marker file.
 *
 * Returns a null `appDir` when nothing resolves rather than throwing —
 * callers decide whether that's fatal: a real command execution needs one
 * (see `AbstractAppCommand::bootstrapApp()`), `bin/quiote`'s opportunistic
 * pre-bootstrap does not (no app found just means no plugin commands show up
 * yet, exactly like today).
 */
final class AppDirResolver
{
    private const MARKER_FILE = '.quiote.json';
    private const SETTINGS_EXTENSIONS = ['php', 'xml', 'yaml', 'yml'];

    private function __construct() {}

    /** @return array{appDir: ?string, env: ?string} */
    public static function resolve(?string $appDirOption = null, ?string $envOption = null): array
    {
        $env = $envOption ?: (getenv('QUIOTE_ENV') ?: null);

        $appDirOption ??= (getenv('QUIOTE_APP_DIR') ?: null);
        if ($appDirOption !== null) {
            return ['appDir' => self::realOrThrow($appDirOption), 'env' => $env];
        }

        $cwd = getcwd();
        if ($cwd === false) {
            return ['appDir' => null, 'env' => $env];
        }

        $marker = self::findUpward($cwd, self::MARKER_FILE);
        if ($marker !== null) {
            $fromMarker = self::readMarker($marker, $env);
            if ($fromMarker !== null) {
                return $fromMarker;
            }
        }

        return ['appDir' => self::findSettingsDir($cwd), 'env' => $env];
    }

    private static function realOrThrow(string $appDir): string
    {
        $real = realpath($appDir);
        if ($real === false || !is_dir($real)) {
            throw new QuioteException(sprintf('App directory "%s" does not exist.', $appDir));
        }
        return $real;
    }

    /** @return array{appDir: string, env: ?string}|null Null if the marker had no usable app_dir. */
    private static function readMarker(string $markerFile, ?string $env): ?array
    {
        $contents = @file_get_contents($markerFile);
        if ($contents === false) {
            return null;
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }
        if ($env === null && isset($decoded['env']) && is_string($decoded['env'])) {
            $env = $decoded['env'];
        }
        if (!isset($decoded['app_dir']) || !is_string($decoded['app_dir']) || $decoded['app_dir'] === '') {
            return null;
        }
        $appDir = $decoded['app_dir'];
        if (!self::isAbsolute($appDir)) {
            $appDir = dirname($markerFile) . '/' . $appDir;
        }
        $real = realpath($appDir);
        if ($real === false || !is_dir($real)) {
            return null;
        }
        return ['appDir' => $real, 'env' => $env];
    }

    private static function isAbsolute(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    private static function findUpward(string $startDir, string $filename): ?string
    {
        $dir = $startDir;
        while ($dir !== '') {
            $candidate = $dir . '/' . $filename;
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return null;
    }

    private static function findSettingsDir(string $startDir): ?string
    {
        $dir = $startDir;
        while ($dir !== '') {
            foreach (self::SETTINGS_EXTENSIONS as $extension) {
                if (is_file($dir . '/Config/settings.' . $extension)) {
                    return $dir;
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return null;
    }
}
