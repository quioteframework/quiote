<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Cache\CacheManager;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ActionExecutor;
use Quiote\Execution\ExecutionState;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Testing\UnitTestCase;
use Quiote\Telemetry\TelemetryBootstrap;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/**
 * Tests for the action span and its nested view-render span.
 * Reuses the sandbox app's "Cache" module —
 * the same real end-to-end fixture DispatchMiddlewareContextSimpleTest uses
 * — rather than hand-rolled Action/View doubles, so this exercises the real
 * ActionExecutor/ViewFactory path, not a simplified stand-in.
 */
class TelemetryActionSpanTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('core.cache_dir', sys_get_temp_dir() . '/quiote_telemetry_action_span_test');
        $dir = Config::get('core.cache_dir');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        CacheManager::reset();
        putenv('QUIOTE_DISPATCH_CONTEXT=1');
        putenv('QUIOTE_DISPATCH_CONTEXT_SIMPLE=1');
        $this->getContext()->getController()->initializeModule('Cache');
        TelemetryBootstrap::reset();
    }

    #[After]
    public function tearDownTelemetry(): void
    {
        TelemetryBootstrap::reset();
        Config::remove('telemetry.enabled');
        Config::remove('telemetry.exporter');
        Config::remove('telemetry.export.mode');
        Config::remove('telemetry.sampling.strategy');
        Config::remove('telemetry.spans.action');
    }

    private function enable(): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true);
        Config::set('telemetry.sampling.strategy', 'always_on', true);
        TelemetryBootstrap::configureFromConfig();
    }

    private function buildPsr(): \Psr\Http\Message\ServerRequestInterface
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller, 'Cache', 'Cache', 'GET', 'html');
        return (new ServerRequest('GET', 'http://localhost/cache'))
            ->withAttribute('module', 'Cache')
            ->withAttribute('action', 'Cache')
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $descriptor);
    }

    private function dispatchCacheAction(): void
    {
        $controller = $this->getContext()->getController();
        $controller->createActionInstance('Cache', 'Cache');
        $mw = new DispatchMiddleware($controller);
        $state = new ExecutionState();
        $handler = new class(new Psr17Factory()) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private $f) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
            {
                return $this->f->createResponse(200);
            }
        };
        $mw->process($this->buildPsr()->withAttribute(ExecutionState::class, $state), $handler);
    }

    private function exportedSpans(): array
    {
        return TelemetryBootstrap::inMemorySpanExporter()->getSpans();
    }

    // --- happy path: action span + nested view span ----------------------------

    public function testActionSpanIsCreatedWithModuleActionAttributes(): void
    {
        $this->enable();
        $this->dispatchCacheAction();

        $spans = $this->exportedSpans();
        $action = array_values(array_filter($spans, static fn($s) => $s->getName() === 'Cache:Cache'));
        $this->assertCount(1, $action);
        $attrs = iterator_to_array($action[0]->getAttributes());
        $this->assertSame('Cache', $attrs['quiote.module']);
        $this->assertSame('Cache', $attrs['quiote.action']);
        $this->assertSame('html', $attrs['quiote.output_type']);
    }

    public function testViewSpanIsANestedChildOfTheActionSpan(): void
    {
        $this->enable();
        $this->dispatchCacheAction();

        $spans = $this->exportedSpans();
        $byName = [];
        foreach ($spans as $s) {
            $byName[$s->getName()] = $s;
        }
        $this->assertArrayHasKey('Cache:Cache', $byName, 'action span');
        $this->assertArrayHasKey('Cache:CacheSuccess', $byName, 'view span, named "{module}:{viewName}"');

        $this->assertSame(
            $byName['Cache:Cache']->getContext()->getSpanId(),
            $byName['Cache:CacheSuccess']->getParentSpanId(),
            'the view span must be a child of the action span'
        );
    }

    // --- depth toggle -------------------------------------------------------

    public function testSpansActionFalseCreatesNeitherActionNorViewSpan(): void
    {
        $this->enable();
        Config::set('telemetry.spans.action', false, true);

        $this->dispatchCacheAction();

        $this->assertCount(0, $this->exportedSpans());
    }

    // --- failure path: exception during action execution ------------------------

    public function testExceptionDuringActionIsRecordedOnTheActionSpanAndRethrown(): void
    {
        $this->enable();
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller, 'Cache', 'Cache', 'GET', 'html');

        $throwingAction = new class extends \Sandbox\Modules\Cache\Actions\CacheAction {
            #[\Override]
            public function execute(\Quiote\Request\WebRequest $rd)
            {
                throw new \RuntimeException('action blew up');
            }
        };

        $executor = new ActionExecutor($controller);
        $state = new ExecutionState();
        $state->securityDecision = \Quiote\Execution\SecurityDecision::Allow;

        $caught = null;
        try {
            $executor->execute($descriptor, new ServerRequest('GET', '/cache'), $state, [], $throwingAction);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'the exception must propagate to DispatchMiddleware, not be swallowed');
        $this->assertSame('action blew up', $caught->getMessage());

        $spans = $this->exportedSpans();
        $action = array_values(array_filter($spans, static fn($s) => $s->getName() === 'Cache:Cache'));
        $this->assertCount(1, $action, 'the action span must still be exported even though the action failed');
        $this->assertSame('Error', $action[0]->getStatus()->getCode());
    }
}
