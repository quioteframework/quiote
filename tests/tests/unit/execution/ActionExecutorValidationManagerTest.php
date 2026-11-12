<?php

use Nyholm\Psr7\ServerRequest;
use Quiote\Action\Action;
use Quiote\Controller\Controller;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ActionExecutionContext;
use Quiote\Execution\ActionExecutor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Execution\SecurityDecision;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ValidationManager;

/**
 * The action and its view use one validation manager: the one validation ran with, when the action
 * arrives already validated. An error the action reports is then the error its view renders.
 */
final class ActionExecutorValidationManagerTest extends UnitTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->controller()->initializeModule('Cache');
    }

    private function controller(): Controller
    {
        return $this->getContext()->getContainer()->get(Controller::class);
    }

    private function recordingAction(): ValidationManagerRecordingAction
    {
        return new ValidationManagerRecordingAction();
    }

    private function execute(Action $action): ActionExecutionContext
    {
        $state = new ExecutionState();
        $state->securityDecision = SecurityDecision::Allow;
        $descriptor = ActionDescriptor::fromController($this->controller(), 'Cache', 'Cache', 'GET', 'html');

        return (new ActionExecutor($this->controller()))->execute($descriptor, new ServerRequest('GET', '/cache'), $state, [], $action);
    }

    private function viewManager(ActionExecutionContext $context): ?ValidationManager
    {
        $this->assertNotNull($context->view);
        return $context->view->getInitContext()?->getValidationManager();
    }

    public function testAValidatedActionKeepsTheManagerValidationRanWithAndItsViewReadsIt(): void
    {
        $action = $this->recordingAction();
        $initContext = new LightweightActionInitContext(
            $this->getContext(), 'Cache', 'Cache', 'read', 'html',
            $this->getContext()->getContainer()->get(WebRequest::class),
            $this->controller()->getGlobalResponse(),
        );
        $validated = $this->getContext()->getContainer()->get(ValidationManager::class);
        $initContext->setValidationManager($validated);
        $action->initialize($initContext);

        $context = $this->execute($action);

        $this->assertSame($validated, $action->seen);
        $this->assertSame($validated, $this->viewManager($context));
    }

    public function testAnActionWithoutAPriorContextSharesItsManagerWithItsView(): void
    {
        $action = $this->recordingAction();

        $context = $this->execute($action);

        $this->assertInstanceOf(ValidationManager::class, $action->seen);
        $this->assertSame($action->seen, $this->viewManager($context));
    }

    public function testTwoValidationManagerLookupsAreDistinctInstances(): void
    {
        // Why sharing has to be explicit: the container builds a fresh manager per lookup, so a
        // view that resolved its own would always read an empty report.
        $container = $this->getContext()->getContainer();
        $this->assertNotSame($container->get(ValidationManager::class), $container->get(ValidationManager::class));
    }
}

/**
 * Runs as the sandbox Cache module's action, records the validation manager it sees while it runs,
 * and renders the Success view.
 */
final class ValidationManagerRecordingAction extends Action
{
    public ?ValidationManager $seen = null;

    public function execute(WebRequest $rd): string
    {
        $this->seen = $this->getInitContext()?->getValidationManager();
        return 'Success';
    }
}
