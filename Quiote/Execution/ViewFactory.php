<?php
namespace Quiote\Execution;

use Quiote\Controller\Controller;
use Quiote\View\View;
use Quiote\Request\WebRequest;
use Quiote\Validator\ValidationManager;

/**
 * ViewFactory: creates and initializes a view using ImmutableViewInitContext.
 * Thin wrapper to allow future injection of decorators, instrumentation, or pooling.
 */
class ViewFactory
{
    public function __construct(private readonly Controller $controller) {}

    /**
     * Create and initialize a view.
     *
     * A view the action asked for that cannot be created is an error, not an empty
     * response: a missing class surfaces as the controller's
     * {@see \Quiote\Exception\ClassNotFoundException}, naming the classes it tried,
     * and an exception from the view's own `initialize()` propagates unchanged.
     *
     * @param string $viewModule Resolved module for the view
     * @param string $viewName Canonical view name
     * @param string $actionModule Original action module
     * @param string $actionName Original action name
     * @param string $outputType Output type name (lowercase)
     * @param ?WebRequest $request Request data snapshot
     * @param array<array-key, mixed> $actionAttributeSnapshot Attributes snapshot from action exec
     * @throws \Quiote\Exception\ClassNotFoundException if no class implements the view.
     */
    public function create(string $viewModule, string $viewName, string $actionModule, string $actionName, string $outputType, ?WebRequest $request, array $actionAttributeSnapshot, ?ValidationManager $validationManager = null): View
    {
        $view = $this->controller->createViewInstance($viewModule, $viewName);
        $global = $this->controller->getGlobalResponse();
        $view->initialize(new ImmutableViewInitContext(
            context: $this->controller->getContext(),
            viewModule: $viewModule,
            viewName: $viewName,
            outputType: $outputType,
            actionModule: $actionModule,
            actionName: $actionName,
            actionAttributes: $actionAttributeSnapshot,
            response: $global,
            psrResponse: new \Quiote\Http\PsrResponseAdapter($global),
            validationManager: $validationManager
        ));
        return $view;
    }
}
