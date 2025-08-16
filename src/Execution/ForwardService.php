<?php
namespace Agavi\Execution;

use Agavi\Controller\AgaviController;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

/**
 * ForwardService: resolves forward targets (login / secure / custom) without creating a full execution container.
 * Phase 1: only system forwards used by security (login, secure).
 * Future: generalize to arbitrary forward tokens returned by actions (array/module override forms).
 */
final class ForwardService
{
    public function __construct(private AgaviController $controller, private ?ViewNameResolver $resolver = null, private ?ViewFactory $viewFactory = null)
    {
        $this->resolver ??= new ViewNameResolver();
        $this->viewFactory ??= new ViewFactory($controller);
    }

    /**
     * Create a view for a system forward (login or secure) returning tuple [AgaviView|null, viewModule, viewName, content].
     * Content is produced immediately (execute* run) so caller can short-circuit dispatch.
     */
    public function createSystemForwardView(string $forwardName, string $outputType, AgaviRequestDataHolder $rd): array
    {
        // Resolve module/action from settings (matches AgaviExecutionContainer::createSystemActionForwardContainer logic)
        $confKeyModule = 'actions.' . strtolower($forwardName) . '_module';
        $confKeyAction = 'actions.' . strtolower($forwardName) . '_action';
        $module = \Agavi\Config\AgaviConfig::get($confKeyModule, 'Default');
        $action = \Agavi\Config\AgaviConfig::get($confKeyAction, ucfirst($forwardName));
    // Try canonical pattern first: <ActionName><ViewSuffix> (e.g. SecureSuccess, LoginSuccess)
    $baseSuffix = 'Success';
    $candidateRaw = $action . $baseSuffix;
    [$vm,$vn] = $this->resolver->resolve($module, $action, $candidateRaw);
    $view = $this->viewFactory->create($vm, $vn, $module, $action, strtolower($outputType), $rd, []);
    if(!$view) {
        // Fallback: plain Success
        [$vm,$vn] = $this->resolver->resolve($module, $action, $baseSuffix);
        $view = $this->viewFactory->create($vm, $vn, $module, $action, strtolower($outputType), $rd, []);
    }
        $content = '';
        if($view) {
            $method = 'execute' . ucfirst(strtolower($outputType));
            if(!is_callable([$view,$method])) { $method = 'execute'; }
            try {
                $res = $view->$method($rd);
                if($res !== null) { $content = (string)$res; }
                elseif(method_exists($view,'getLayers') && method_exists($view,'renderLayers') && $view->getLayers()) {
                    // Attempt layer rendering for classic layout-driven system views
                    try { $layerOut = $view->renderLayers(); if($layerOut !== '') { $content = $layerOut; } } catch(\Throwable) {}
                }
            } catch(\Throwable) {}
        }
        // No synthetic fallback: return empty string if view produced no content so callers can decide.
        return [$view,$vm,$vn,$content];
    }
}
?>
