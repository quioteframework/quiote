<?php
declare(strict_types=1);

namespace Quiote\Middleware\Config;

use Psr\Http\Server\MiddlewareInterface;
use Quiote\Config\Config;
use Quiote\Exception\ConfigurationException;
use Quiote\Middleware\MiddlewarePipeline;

/**
 * Process-global accumulator for `<use>` entries contributed by compiled
 * `middleware.{xml,php,yaml,yml}` files (the app's own plus any module's
 * `Config/middleware.*`), mirroring {@see \Quiote\Plugin\PluginManager}'s
 * role for `plugins.*`.
 *
 * Contributions are validated here, at compile/bootstrap time, rather than
 * deferred to {@see MiddlewarePipeline}'s first build: a config file that
 * tries to touch one of the framework's own shipped middleware classes
 * (see {@see MiddlewarePipeline::coreMiddlewareClasses()}) without both the
 * per-entry `override-framework="true"` attribute AND the global
 * `core.middleware.allow_framework_overrides` setting fails loudly the
 * moment that file is loaded, not on the first HTTP request.
 * @since      1.0.0
 */
final class MiddlewareConfigRegistry
{
    public const OVERRIDE_SETTING = 'core.middleware.allow_framework_overrides';

    /**
     * @var list<array{class: string, phase: ?string, priority: ?int, before: ?string, after: ?string, enabled: ?bool, override_framework: bool, sourceRef: string}>
     */
    private static array $entries = [];

    /**
     * @param list<array{class: string, phase: ?string, priority: ?int, before: ?string, after: ?string, enabled: ?bool, override_framework: bool}> $entries
     */
    public static function contribute(array $entries, string $sourceRef): void
    {
        foreach ($entries as $entry) {
            self::assertValidClass($entry['class'], $sourceRef);
            self::guardFrameworkOverride($entry, $sourceRef);
            self::$entries[] = $entry + ['sourceRef' => $sourceRef];
        }
    }

    /** @return list<array{class: string, phase: ?string, priority: ?int, before: ?string, after: ?string, enabled: ?bool, override_framework: bool, sourceRef: string}> */
    public static function all(): array
    {
        return self::$entries;
    }

    /** Test isolation. */
    public static function reset(): void
    {
        self::$entries = [];
    }

    private static function assertValidClass(string $fqcn, string $sourceRef): void
    {
        if (!class_exists($fqcn)) {
            throw new ConfigurationException(sprintf(
                'Middleware config in "%s" references unknown class "%s".',
                $sourceRef,
                $fqcn
            ));
        }
        if (!is_a($fqcn, MiddlewareInterface::class, true)) {
            throw new ConfigurationException(sprintf(
                'Middleware config in "%s" references "%s", which does not implement %s.',
                $sourceRef,
                $fqcn,
                MiddlewareInterface::class
            ));
        }
    }

    /**
     * @param array{class: string, phase: ?string, priority: ?int, before: ?string, after: ?string, enabled: ?bool, override_framework: bool} $entry
     */
    private static function guardFrameworkOverride(array $entry, string $sourceRef): void
    {
        if (!in_array($entry['class'], MiddlewarePipeline::coreMiddlewareClasses(), true)) {
            return;
        }

        $touchesPlacement = $entry['phase'] !== null || $entry['priority'] !== null
            || $entry['before'] !== null || $entry['after'] !== null;
        $touchesEnabled = $entry['enabled'] !== null;

        if (!$touchesPlacement && !$touchesEnabled) {
            return;
        }

        if ($entry['override_framework'] && Config::getBool(self::OVERRIDE_SETTING, false)) {
            return;
        }

        throw new ConfigurationException(sprintf(
            'Middleware config in "%s" tries to reorder or toggle framework middleware "%s" without '
            . 'authorization. Add override-framework="true" to that <use> entry AND set "%s" to true '
            . '-- both are required, deliberately, so a config file can\'t silently reorder or disable '
            . 'core middleware (error handling, sessions, CSRF, security, routing).',
            $sourceRef,
            $entry['class'],
            self::OVERRIDE_SETTING
        ));
    }
}
