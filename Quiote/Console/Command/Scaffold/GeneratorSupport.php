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

    public static function appDir(): string
    {
        return Config::getString('core.app_dir');
    }

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

    public static function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new ConfigurationException(sprintf('Could not create directory "%s".', $dir));
        }
        if (file_put_contents($path, $content) === false) {
            throw new ConfigurationException(sprintf('Could not write file "%s".', $path));
        }
    }
}
