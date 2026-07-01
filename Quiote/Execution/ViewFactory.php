<?php
namespace Quiote\Execution;

use Quiote\Controller\Controller;
use Quiote\View\View;
use Quiote\Request\WebRequest;

/**
 * ViewFactory: creates and initializes a view using ImmutableViewInitContext.
 * Thin wrapper to allow future injection of decorators, instrumentation, or pooling.
 */
class ViewFactory
{
    public function __construct(private readonly Controller $controller) {}

    /**
     * Create and initialize a view.
     * @param string $viewModule Resolved module for the view
     * @param string $viewName Canonical view name
     * @param string $actionModule Original action module
     * @param string $actionName Original action name
     * @param string $outputType Output type name (lowercase)
     * @param WebRequest|null $requestData Request data snapshot
     * @param array $actionAttributeSnapshot Attributes snapshot from action exec
     */
    public function create(string $viewModule, string $viewName, string $actionModule, string $actionName, string $outputType, ?WebRequest $request, array $actionAttributeSnapshot, ?object $validationManager = null): ?View
    {
        try {
            /** @var View $view */
            $view = $this->controller->createViewInstance($viewModule, $viewName);
            $global = $this->controller->getGlobalResponse();
            $psr = null;
            if ($global instanceof \Quiote\Response\WebResponse) {
                $psr = new \Quiote\Http\PsrResponseAdapter($global);
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
