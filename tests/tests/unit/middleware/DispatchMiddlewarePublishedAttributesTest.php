<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Action\Action;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\SecurityDecision;
use Quiote\Execution\ValidationDecision;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\View\View;

/**
 * DispatchMiddleware publishes its own request once the action has run, so the final
 * ExecutionState is reachable through RequestState. What the action or its view published while
 * it ran has to survive that: a view's form population values are read from there afterwards.
 */
#[RunTestsInSeparateProcesses]
final class DispatchMiddlewarePublishedAttributesTest extends UnitTestCase
{
    private function requestState(): RequestState
    {
        return $this->getContext()->getContainer()->get(RequestState::class);
    }

    /**
     * Dispatches an action whose execute() runs $duringExecute, and answers the request as
     * RequestState::current() has it afterwards.
     *
     * @param \Closure(RequestState): void $duringExecute
     * @return array{WebRequest, ExecutionState, ActionDescriptor}
     */
    private function dispatch(\Closure $duringExecute): array
    {
        $controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
        $controller->initializeModule('Cache');
        $requestState = $this->requestState();

        $requestState->publish($requestState->current()
            ->withAttribute('app.unchanged', 'before dispatch')
            ->withAttribute('app.changed', 'before dispatch'));

        $action = new class($requestState, $duringExecute) extends Action {
            public function __construct(private readonly RequestState $requestState, private readonly \Closure $duringExecute) {}

            public function execute(WebRequest $rd): null
            {
                ($this->duringExecute)($this->requestState);
                return View::NONE;
            }
        };

        $state = new ExecutionState();
        $state->securityDecision = SecurityDecision::Allow;
        $state->validationDecision = ValidationDecision::passed();
        $descriptor = new ActionDescriptor('Cache', 'Cache', 'read', 'html', false);
        $request = $requestState->current()
            ->withAttribute('app.unchanged', 'dispatch request')
            ->withAttribute('quiote.rid', 'dispatch-rid')
            ->withAttribute(ExecutionState::class, $state)
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute('quiote.preinstantiated_action', $action);

        $handler = new readonly class(new Psr17Factory()) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $factory) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };
        (new DispatchMiddleware($controller))->process($request, $handler);

        return [$requestState->current(), $state, $descriptor];
    }

    public function testAttributesTheActionPublishedSurviveDispatch(): void
    {
        [$current] = $this->dispatch(static function (RequestState $requestState): void {
            $requestState->publish($requestState->current()
                ->withAttribute('app.changed', 'published by the action')
                ->withAttribute('app.added', 'published by the action'));
        });

        $this->assertSame('published by the action', $current->getAttribute('app.changed'));
        $this->assertSame('published by the action', $current->getAttribute('app.added'));
    }

    public function testAttributesTheActionLeftAloneKeepTheDispatchRequestsValue(): void
    {
        [$current] = $this->dispatch(static function (RequestState $requestState): void {
            $requestState->publish($requestState->current()->withAttribute('app.added', 'published by the action'));
        });

        $this->assertSame('dispatch request', $current->getAttribute('app.unchanged'));
        $this->assertSame('before dispatch', $current->getAttribute('app.changed'), 'untouched by the action, so the dispatch request (which inherited it) wins');
    }

    public function testTheCorrelationIdExecutionStateAndDescriptorStayDispatchsOwn(): void
    {
        [$current, $state, $descriptor] = $this->dispatch(static function (RequestState $requestState): void {
            $requestState->publish($requestState->current()
                ->withAttribute('quiote.rid', 'action-rid')
                ->withAttribute(ExecutionState::class, new ExecutionState())
                ->withAttribute(ActionDescriptor::class, new ActionDescriptor('Other', 'Other', 'read', 'html', false)));
        });

        $this->assertSame('dispatch-rid', $current->getAttribute('quiote.rid'));
        $this->assertSame($state, $current->getAttribute(ExecutionState::class));
        $this->assertSame($descriptor, $current->getAttribute(ActionDescriptor::class));
    }

    public function testAnActionThatPublishesNothingLeavesTheDispatchRequest(): void
    {
        [$current, $state] = $this->dispatch(static function (RequestState $requestState): void {});

        $this->assertNull($current->getAttribute('app.added'));
        $this->assertSame('dispatch request', $current->getAttribute('app.unchanged'));
        $this->assertSame($state, $current->getAttribute(ExecutionState::class));
    }
}
