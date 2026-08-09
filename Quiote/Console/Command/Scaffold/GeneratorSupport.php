<?php
declare(strict_types=1);

namespace Quiote\Console\Command\Scaffold;

use Quiote\Config\Config;
use Quiote\Exception\ConfigurationException;

/**
 * Shared validation/overwrite-guard helpers for the `make:*` generator
 * commands, mirroring the checks `NewCommand` already applies to its own
 * `--namespace` argument (see `NewCommand::execute()`).
 */
final class GeneratorSupport
{
    private function __construct()
    {
    }

    /** @throws ConfigurationException if $name is not a valid PHP class-name segment */
    public static function validateClassNameSegment(string $name): void
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            throw new ConfigurationException(sprintf(
                '"%s" is not a valid name (expected e.g. "Post", "SendWelcomeEmail" -- PascalCase, letters/digits only).',
                $name
            ));
        }
    }

    /** @throws ConfigurationException if $path exists and $force is false */
    public static function guardOverwrite(string $path, bool $force): void
    {
        if (is_file($path) && !$force) {
            throw new ConfigurationException(sprintf(
                '"%s" already exists. Use --force to overwrite it.',
                $path
            ));
        }
    }

    /** Returns the application root directory the generators write into, from `core.app_dir`. */
    public static function appDir(): string
    {
        return Config::getString('core.app_dir');
    }

    /**
     * Returns the root namespace for generated classes, from `core.namespace_prefix`
     * and defaulting to `App`.
     *
     * Surrounding backslashes are trimmed, so the result is always usable as a
     * namespace segment that callers can concatenate to.
     */
    public static function appNamespace(): string
    {
        return trim(Config::getString('core.namespace_prefix', 'App'), '\\');
    }

    /** Symfony Console's getArgument()/getOption() are typed mixed; every caller here expects a scalar string. */
    public static function requireString(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new ConfigurationException(sprintf('%s must be a string.', $label));
        }
        return $value;
    }

    /**
     * Writes a generated file, creating its parent directory tree if needed.
     *
     * Overwrites unconditionally — call {@see guardOverwrite()} first if the
     * command honours a `--force` flag. Both the directory creation and the write
     * are error-suppressed so the generator reports one clear exception instead of
     * a raw PHP warning followed by it.
     *
     * @throws ConfigurationException If the directory cannot be created or the file
     *                                cannot be written.
     */
    public static function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        // Both calls warn on failure, and each failure is reported as a
        // ConfigurationException right below -- a raw PHP warning on top of that
        // just puts noise in front of the generator's own error message.
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new ConfigurationException(sprintf('Could not create directory "%s".', $dir));
        }
        if (@file_put_contents($path, $content) === false) {
            throw new ConfigurationException(sprintf('Could not write file "%s".', $path));
        }
    }
}
