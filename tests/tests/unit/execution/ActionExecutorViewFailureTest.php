<?php

use Nyholm\Psr7\ServerRequest;
use Quiote\Action\Action;
use Quiote\Controller\Controller;
use Quiote\Exception\ClassNotFoundException;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ActionExecutionContext;
use Quiote\Execution\ActionExecutor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\SecurityDecision;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\View\View;

/**
 * What a request does when the view its action names cannot be built: it fails, so the
 * error-handling middleware decides what the client sees, rather than answering 200 with an
 * empty body that nothing logs.
 */
final class ActionExecutorViewFailureTest extends UnitTestCase
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

    /** Runs an action in the sandbox Cache module whose execute() returns $viewName. */
    private function executeReturning(?string $viewName): ActionExecutionContext
    {
        $action = new class($viewName) extends Action {
            public function __construct(private readonly ?string $returnedView) {}

            public function execute(WebRequest $rd): ?string
            {
                return $this->returnedView;
            }
        };

        $state = new ExecutionState();
        $state->securityDecision = SecurityDecision::Allow;
        $descriptor = ActionDescriptor::fromController($this->controller(), 'Cache', 'Cache', 'GET', 'html');

        return (new ActionExecutor($this->controller()))->execute($descriptor, new ServerRequest('GET', '/cache'), $state, [], $action);
    }

    public function testAnExistingViewRendersItsContent(): void
    {
        $context = $this->executeReturning('Success');

        $this->assertInstanceOf(View::class, $context->view);
        $this->assertSame('<div>CACHE_HTML</div>', $context->content);
    }

    public function testNoneRendersNothingWithoutFailing(): void
    {
        $context = $this->executeReturning(View::NONE);

        $this->assertNull($context->view);
        $this->assertSame('', $context->content);
    }

    public function testAViewNameWithNoClassFailsTheRequest(): void
    {
        try {
            $this->executeReturning('Missing');
            $this->fail('a missing view must not render as an empty response');
        } catch (ClassNotFoundException $e) {
            $this->assertStringContainsString('Sandbox\\Modules\\Cache\\Views\\CacheMissingView', $e->getMessage());
            $this->assertStringContainsString('View "CacheMissing" in Module "Cache"', $e->getMessage());
        }
    }

    public function testAnExceptionFromTheViewsInitializeReachesTheCaller(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('view initialize exploded');
        $this->executeReturning('BrokenInit');
    }
}
