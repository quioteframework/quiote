<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

use Quiote\DI\Container;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use RuntimeException;

/**
 * Registers the filesystem subsystem: `filesystem.*` setting defaults (`local`
 * disk rooted at `storage/app`, out of the box) and the {@see FilesystemManager}
 * service app code depends on. A cloud backend (e.g. `quioteframework/filesystem-s3`)
 * registers its own alias into {@see FilesystemDriverRegistry} from its own
 * plugin — this class does not need to change for that.
 *
 * Like every plugin, this is opt-in via the `plugins` config key — even
 * though it lives in core, an app must list it to get {@see FilesystemManager}.
 */
#[PluginAttribute(name: 'quiote/filesystem')]
final class FilesystemPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('filesystem.default_disk', 'local');
        $registrar->configDefault('filesystem.disks.local.root', 'storage/app');

        $registrar->service(FilesystemConfig::class, static fn() => FilesystemConfig::fromConfig());

        $registrar->service(
            LocalFilesystemAdapter::class,
            static fn(Container $container) => new LocalFilesystemAdapter(self::resolveFilesystemConfig($container)->localRoot),
        );

        $registrar->service(
            FilesystemManager::class,
            static fn(Container $container) => new FilesystemManager($container, self::resolveFilesystemConfig($container)),
        );
    }

    private static function resolveFilesystemConfig(Container $container): FilesystemConfig
    {
        $config = $container->get(FilesystemConfig::class);
        if (!$config instanceof FilesystemConfig) {
            throw new RuntimeException(sprintf('Expected "%s" service to be a FilesystemConfig, got %s.', FilesystemConfig::class, get_debug_type($config)));
        }
        return $config;
    }
}
