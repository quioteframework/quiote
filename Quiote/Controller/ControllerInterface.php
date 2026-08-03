<?php

namespace Quiote\Controller;

use Quiote\Action\Action;
use Quiote\Response\WebResponse;
use Quiote\View\View;

/**
 * What the framework asks of a controller: resolve and build the action and view for a
 * dispatch, answer questions about what a module contains, and hold the response being
 * assembled.
 *
 * Narrower than {@see Controller}: startup(), shutdown(), reset() and initialize() drive the
 * controller's own lifecycle and belong to the context that owns it, not to the middleware and
 * services that dispatch through it.
 *
 * @since      3.2.0
 */
interface ControllerInterface
{
    /**
     * The context this controller belongs to.
     * @return     \Quiote\Context
     */
    public function getContext();

    /**
     * The response being assembled for this request.
     */
    public function getGlobalResponse(): WebResponse;

    /**
     * Build (and initialize) an action instance for a module.
     * @param      string $moduleName
     * @param      string $actionName
     */
    public function createActionInstance($moduleName, $actionName): Action;

    /**
     * Build a view instance for a module.
     * @param      string $moduleName
     * @param      string $viewName
     */
    public function createViewInstance($moduleName, $viewName): View;

    /**
     * Load a module's autoload and configuration, once per process.
     * @param      string $moduleName
     * @return     mixed
     * @throws     \Quiote\Exception\DisabledModuleException If the module is disabled.
     */
    public function initializeModule($moduleName);

    /**
     * Whether a module exists.
     * @param      string $moduleName
     * @return     bool
     */
    public function moduleExists($moduleName);

    /**
     * Whether an action exists in a module.
     * @param      string $moduleName
     * @param      string $actionName
     * @return     bool
     */
    public function actionExists($moduleName, $actionName);

    /**
     * Whether a view exists in a module.
     * @param      string $moduleName
     * @param      string $viewName
     * @return     bool
     */
    public function viewExists($moduleName, $viewName);

    /**
     * Whether a model exists in a module.
     * @param      string $moduleName
     * @param      string $modelName
     * @return     bool
     */
    public function modelExists($moduleName, $modelName);

    /**
     * A configured output type by name, or the default when $name is null.
     * @param      ?string $name
     * @return     OutputType
     */
    public function getOutputType($name = null);

    /**
     * The names of every configured output type.
     * @return     array<int, string>
     */
    public function getOutputTypeNames(): array;
}
