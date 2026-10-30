<?php

declare(strict_types=1);

namespace Quiote\DevOverlay;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DevOverlay\Http\HtmlScriptInjector;
use Quiote\DevOverlay\Http\OverlayEndpoint;
use Quiote\DevOverlay\Report\ExceptionReportBuilder;
use Quiote\DevOverlay\Report\ExceptionReportStoreInterface;
use Quiote\DevOverlay\Report\FileExceptionReportStore;
use Quiote\DI\Container;
use Quiote\Http\Psr17;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Random\RandomnessInterface;

/**
 * Registers the developer overlay: the `dev_overlay.*` defaults, the report store and
 * {@see DevOverlayMiddleware}.
 *
 * Listing the plugin is the opt-in, and `core.developer_exceptions` is the gate -- with that off,
 * the middleware passes every request straight through, exactly as Whoops falls back to the safe
 * renderer. `dev_overlay.enabled` exists to switch the overlay off while keeping Whoops.
 *
 * The store is bound as {@see ExceptionReportStoreInterface}, so an app running several hosts
 * behind one URL -- where the follow-up fetch for a report may reach a different host than the
 * request that wrote it -- can bind a shared implementation in its place.
 */
#[PluginAttribute(name: 'quioteframework/dev-overlay')]
final class DevOverlayPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('dev_overlay.enabled', true);
        $registrar->configDefault('dev_overlay.path', '/_quiote/overlay');
        // Null resolves to <core.cache_dir>/dev-overlay once a context exists to have a cache dir.
        $registrar->configDefault('dev_overlay.store_path', null);
        $registrar->configDefault('dev_overlay.max_reports', 50);
        // A URL template with {file} and {line}, e.g. "vscode://file/{file}:{line}" or
        // "phpstorm://open?file={file}&line={line}". Empty shows locations as plain text.
        $registrar->configDefault('dev_overlay.editor_url', '');

        $registrar->service(
            ExceptionReportStoreInterface::class,
            static fn(): ExceptionReportStoreInterface => new FileExceptionReportStore(
                Config::getNullableString('dev_overlay.store_path')
                    ?? Config::getString('core.cache_dir', sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'dev-overlay',
                Config::getInt('dev_overlay.max_reports', 50),
            ),
            Container::SCOPE_SINGLETON,
        );

        $registrar->attributedMiddleware(
            DevOverlayMiddleware::class,
            static function (Context $context): DevOverlayMiddleware {
                $container = $context->getContainer();
                $store = $container->get(ExceptionReportStoreInterface::class);
                $factory = Psr17::factory();

                return new DevOverlayMiddleware(
                    $context,
                    $store,
                    new ExceptionReportBuilder($container->get(ClockInterface::class)),
                    new OverlayEndpoint($store, $factory, $factory),
                    new HtmlScriptInjector(),
                    $factory,
                    $container->get(RandomnessInterface::class),
                );
            },
        );
    }
}
