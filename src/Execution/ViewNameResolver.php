<?php
namespace Agavi\Execution;

use Agavi\View\AgaviView;
use Agavi\Util\AgaviToolkit;

/**
 * ViewNameResolver: pure resolution of raw view return values to (module, canonicalViewName|NONE).
 * Unlike ViewResolver, this class intentionally performs no instantiation or side-effects and
 * can be safely used in container-less pipelines and caching layers.
 */
final class ViewNameResolver
{
    /**
     * @param string $actionModule Declared action module.
     * @param string $actionName Action name.
     * @param mixed $rawViewName Raw return (string|array|AgaviView::NONE)
     * @return array{0:string,1:string|null}
     */
    public function resolve(string $actionModule, string $actionName, mixed $rawViewName): array
    {
        if(is_array($rawViewName)) {
            // Accept legacy array forms: [module, viewName] or [viewName] (implying current module)
            // Provide defensive defaults to avoid E_WARNING: Undefined array key X.
            $viewModule = $rawViewName[0] ?? $actionModule;
            $raw = $rawViewName[1] ?? ($rawViewName[0] ?? AgaviView::NONE);
            if($raw === null || $raw === '') { $raw = AgaviView::NONE; }
        } elseif($rawViewName !== AgaviView::NONE) {
            $evaluated = AgaviToolkit::evaluateModuleDirective(
                $actionModule,
                'agavi.view.name',
                [ 'actionName' => $actionName, 'viewName' => $rawViewName ]
            );
            // Fallback to raw input when directive evaluation yields empty (sandbox modules may not define directive)
            $raw = ($evaluated === '' || $evaluated === null) ? $rawViewName : $evaluated;
            $viewModule = $actionModule;
        } else {
            $viewModule = AgaviView::NONE;
            $raw = AgaviView::NONE;
        }
        if($raw !== AgaviView::NONE) {
            $raw = AgaviToolkit::canonicalName($raw);
        }
        return [$viewModule, $raw];
    }
}
