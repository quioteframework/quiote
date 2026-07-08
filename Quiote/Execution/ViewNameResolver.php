<?php
namespace Quiote\Execution;

use Quiote\View\View;
use Quiote\Util\Toolkit;

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
     * @param mixed $rawViewName Raw return (string|array|View::NONE)
     * @return array{0:string|null,1:string|null}
     */
    public function resolve(string $actionModule, string $actionName, mixed $rawViewName): array
    {
        if(is_array($rawViewName)) {
            // Accept legacy array forms: [module, viewName] or [viewName] (implying current module)
            // Provide defensive defaults to avoid E_WARNING: Undefined array key X.
            $viewModule = $rawViewName[0] ?? $actionModule;
            $raw = $rawViewName[1] ?? ($rawViewName[0] ?? View::NONE);
            if($raw === null || $raw === '') { $raw = View::NONE; }
        } elseif($rawViewName !== View::NONE) {
            $evaluated = Toolkit::evaluateModuleDirective(
                $actionModule,
                'quiote.view.name',
                [ 'actionName' => $actionName, 'viewName' => $rawViewName ]
            );
            // Fallback to raw input when directive evaluation yields empty (sandbox modules may not define directive)
            $raw = ($evaluated === '') ? $rawViewName : $evaluated;
            $viewModule = $actionModule;
        } else {
            $viewModule = View::NONE;
            $raw = View::NONE;
        }
        if($raw !== View::NONE) {
            $raw = Toolkit::canonicalName($raw);
        }
        return [$viewModule, $raw];
    }
}
