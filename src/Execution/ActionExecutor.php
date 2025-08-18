<?php

namespace Agavi\Execution;

use Agavi\Controller\AgaviController;
use Agavi\Action\AgaviAction;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Execution\ValidationService;
use Agavi\Execution\SecurityService; // still injected to keep signature stable (may be removed later)
use Agavi\Execution\SecurityDecision;
use Agavi\Execution\LightweightActionInitContext;
use Agavi\Execution\ImmutableViewInitContext;
use Agavi\Execution\ViewInitContext;
use Agavi\Response\AgaviResponse;
use Agavi\Execution\ViewNameResolver;
use Agavi\Execution\ActionResolver;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ActionExecutor: container-less execution of an action+view producing ActionExecutionContext.
 *
 * Phase 1 scope (incremental):
 * - Security + validation (optional) via services when enabled.
 * - Simple actions: execute() method.
 * - Non-simple actions: use ActionResolver for method dispatch.
 * - View resolution via ViewNameResolver (pure).
 * - View initialization via legacy container (temporary) if needed until ViewFactory extracted.
 *
 * Future phases will remove any dependency on containers entirely.
 */
final class ActionExecutor
{
    public function __construct(
        private AgaviController $controller,
        private ?ActionResolver $actionResolver = null,
        private ?ValidationService $validationService = null,
        private ?SecurityService $securityService = null,
        private ?ViewFactory $viewFactory = null,
        private ?ViewNameResolver $viewNameResolver = null,
        // ForwardService removed from executor (security forwards handled exclusively in SecurityMiddleware)
    ) {
        // Initialize services
        $this->viewNameResolver ??= new ViewNameResolver();
        $this->actionResolver ??= new ActionResolver();
        $this->validationService ??= new ValidationService();
        $this->securityService ??= new SecurityService($controller);
        $this->viewFactory ??= new ViewFactory($controller);
    }

    /**
     * Build an AgaviRequestDataHolder from a PSR-7 ServerRequest without touching the legacy global request.
     * Query params + parsed body are merged (body wins on key collision). Selected scalar route attributes are added.
     */
    public static function buildRequestDataFromPsr(ServerRequestInterface $psr): AgaviRequestDataHolder
    {
        $rd = new AgaviRequestDataHolder();
        $query = $psr->getQueryParams();
        $body = $psr->getParsedBody();
        if (!is_array($query)) {
            $query = [];
        }
        if (!is_array($body)) {
            $body = [];
        }
        // body wins
        $params = $query + $body;
        // include common routing attributes (non-arrays only to avoid complex objects)
        foreach (['module', 'action', 'output_type'] as $attr) {
            $val = $psr->getAttribute($attr);
            if (is_scalar($val) && !isset($params[$attr])) {
                $params[$attr] = $val;
            }
        }
        // RD API: set each parameter individually (AgaviRequestDataHolder inherits parameter holder with setParameter)
        foreach ($params as $k => $v) {
            try {
                $rd->setParameter($k, $v);
            } catch (\Throwable) { /* ignore invalid */
            }
        }
        return $rd;
    }

    /**
     * Execute an action given its descriptor and request data, mutating ExecutionState accordingly.
     * NOTE: For now we still create a lightweight execution container only to satisfy legacy view->initialize expectations.
     * In a later phase a ViewFactory + ViewInitContext will replace this.
     */
    public function execute(ActionDescriptor $desc, AgaviRequestDataHolder $requestData, ExecutionState $state, array $parameters = [], ?AgaviAction $preInstantiatedAction = null): ActionExecutionContext
    {
        // Use provided action instance if supplied to avoid double instantiation (enables external pre-initialization & test counters)
        $action = $preInstantiatedAction ?? $this->controller->createActionInstance($desc->module, $desc->action);
        if (!($action instanceof AgaviAction)) {
            throw new \RuntimeException('Created action is not instance of AgaviAction');
        }
        // Initialize action with lightweight context
        $lwCtx = new LightweightActionInitContext(
            $this->controller->getContext(),
            $desc->module,
            $desc->action,
            $desc->method,
            $desc->outputType,
            $requestData,
            $this->controller->getGlobalResponse()
        );
        $action->initialize($lwCtx);

        // SECURITY: Executor is security-agnostic. Only minimal guard remains.
        if ($state->securityDecision === null) {
            $useSecurity = \Agavi\Config\AgaviConfig::get('core.use_security', true);
            if (!$useSecurity) {
                $state->securityDecision = SecurityDecision::Allow; // global security disabled
            } elseif (method_exists($action, 'isSecure') && !$action->isSecure()) {
                $state->securityDecision = SecurityDecision::Allow; // action explicitly open
            } else {
                throw new \RuntimeException('Security decision missing before action execution for ' . $desc->module . ':' . $desc->action);
            }
        } elseif ($state->securityDecision !== SecurityDecision::Allow) {
            // A non-allow decision should have been short-circuited earlier; treat as logic error.
            throw new \LogicException('Non-allow securityDecision reached ActionExecutor (expected short-circuit).');
        }

        // Validation is performed by ValidationMiddleware earlier. If validation failed that middleware returns a response
        // and this executor is never reached. We only assert consistency here.
        if (!$desc->isSimple && !$state->validationPerformed) {
            throw new \LogicException('Non-simple action executed without prior validation: ' . $desc->module . ':' . $desc->action);
        }

        // ACTION EXECUTION
        $rawView = $this->actionResolver->execute($action, $desc->method, $requestData);
        // Snapshot attributes immediately after action code runs (pre-view)
        $attributeSnapshot = [];
        if (method_exists($action, 'getAttributes')) {
            try {
                $attributeSnapshot = $action->getAttributes();
            } catch (\Throwable) {
                $attributeSnapshot = [];
            }
            // Shallow clone to detach from holder internal storage (defensive)
            if (is_array($attributeSnapshot)) {
                $attributeSnapshot = array_merge([], $attributeSnapshot);
            } else {
                $attributeSnapshot = [];
            }
        }

        [$vm, $vn] = $this->viewNameResolver->resolve($desc->module, $desc->action, $rawView);
        $view = null;
        $content = '';
        if ($vn !== AgaviView::NONE) {
            $view = $this->viewFactory?->create($vm, $vn, $desc->module, $desc->action, strtolower($this->controller->getOutputType()->getName()), $requestData, $attributeSnapshot);
            if (!$view) {
                $view = $this->createAndInitView($vm, $vn, $desc->module, $desc->action, $requestData, $attributeSnapshot);
            }
            $method = $this->selectViewMethod($view, $desc->outputType);
            $res = $view->$method($requestData);
            if ($res !== null) {
                $content = (string)$res;
            } elseif (method_exists($view, 'getLayers') && method_exists($view, 'renderLayers') && $view->getLayers()) {
                $layerContent = $view->renderLayers();
                if ($layerContent !== '') {
                    $content = $layerContent;
                }
            }
        }
        $state->securityDecision = SecurityDecision::Allow;
        $state->viewModule = $vm;
        $state->viewName = $vn;
        $bag = new AttributeBag($attributeSnapshot);
        $respHandle = new ResponseHandle($this->controller->getGlobalResponse());
        return new ActionExecutionContext($action, $view, $desc->module, $desc->action, $desc->outputType, $requestData, $content, $vm, $vn, $attributeSnapshot, $bag, $respHandle);
    }

    private function createAndInitView(string $vm, string $vn, string $module, string $action, AgaviRequestDataHolder $rd, array $attributeSnapshot = []): ?AgaviView
    {
        try {
            /** @var AgaviView $view */
            $view = $this->controller->createViewInstance($vm, $vn);
            // Snapshot action attributes if action already executed (not passed here; minimal for now)
            $vic = new ImmutableViewInitContext(
                context: $this->controller->getContext(),
                viewModule: $vm,
                viewName: $vn,
                outputType: strtolower($this->controller->getOutputType()->getName()),
                actionModule: $module,
                actionName: $action,
                actionAttributes: $attributeSnapshot,
                response: $this->controller->getGlobalResponse()
            );
            $view->initialize($vic);
            return $view;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function selectViewMethod(?AgaviView $view, string $outputType): string
    {
        if (!$view) {
            return 'execute';
        }
        $candidate = 'execute' . ucfirst($outputType);
        return is_callable([$view, $candidate]) ? $candidate : 'execute';
    }
}
