<?php
namespace Agavi\Execution;

use Agavi\Controller\AgaviController;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

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
     * @param AgaviRequestDataHolder|null $requestData Request data snapshot
     * @param array $actionAttributeSnapshot Attributes snapshot from action exec
     */
    public function create(string $viewModule, string $viewName, string $actionModule, string $actionName, string $outputType, ?AgaviRequestDataHolder $requestData, array $actionAttributeSnapshot): ?AgaviView
    {
        try {
            /** @var AgaviView $view */
            $view = $this->controller->createViewInstance($viewModule, $viewName);
            $vic = new ImmutableViewInitContext(
                context: $this->controller->getContext(),
                viewModule: $viewModule,
                viewName: $viewName,
                outputType: $outputType,
                actionModule: $actionModule,
                actionName: $actionName,
                actionAttributes: $actionAttributeSnapshot,
                response: $this->controller->getGlobalResponse()
            );
            $view->initialize($vic);
            return $view;
        } catch(\Throwable) {
            return null;
        }
    }
}
