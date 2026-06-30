<?php
namespace Agavi\Execution;

use Agavi\Controller\AgaviController;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest;

/**
 * ViewFactory: creates and initializes a view using ImmutableViewInitContext.
 * Thin wrapper to allow future injection of decorators, instrumentation, or pooling.
 */
class ViewFactory
{
    public function __construct(private AgaviController $controller) {}

    /**
     * Create and initialize a view.
     * @param string $viewModule Resolved module for the view
     * @param string $viewName Canonical view name
     * @param string $actionModule Original action module
     * @param string $actionName Original action name
     * @param string $outputType Output type name (lowercase)
     * @param AgaviWebRequest|null $requestData Request data snapshot
     * @param array $actionAttributeSnapshot Attributes snapshot from action exec
     */
    public function create(string $viewModule, string $viewName, string $actionModule, string $actionName, string $outputType, ?AgaviWebRequest $request, array $actionAttributeSnapshot, ?object $validationManager = null): ?AgaviView
    {
        try {
            /** @var AgaviView $view */
            $view = $this->controller->createViewInstance($viewModule, $viewName);
            $global = $this->controller->getGlobalResponse();
            $psr = null;
            if ($global instanceof \Agavi\Response\AgaviWebResponse) {
                $psr = new \Agavi\Http\PsrResponseAdapter($global);
            }
            $vic = new ImmutableViewInitContext(
                context: $this->controller->getContext(),
                viewModule: $viewModule,
                viewName: $viewName,
                outputType: $outputType,
                actionModule: $actionModule,
                actionName: $actionName,
                actionAttributes: $actionAttributeSnapshot,
                response: $global,
                psrResponse: $psr,
                validationManager: $validationManager
            );
            $view->initialize($vic);
            return $view;
        } catch(\Throwable) {
            return null;
        }
    }
}
