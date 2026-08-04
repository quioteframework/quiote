<?php

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Asset\AssetRegistry;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Execution\ActionResolver;
use Quiote\Execution\SlotDispatcher;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Runtime\ContextRequestHandler;
use Quiote\Test\Routing\TestRouting;
use Quiote\Testing\PhpUnitTestCase;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ContextRequestHandlerTest extends PhpUnitTestCase
{
	private function ctx(string $name = 'default'): Context
	{
		$context = Context::getInstance($name);
		// The default routing fixture is not dispatchable; the pipeline needs one that is.
		(new ReflectionObject($context))->getProperty('routing')->setValue($context, new TestRouting());

		return $context;
	}

	/**
	 * Context::handle() always had this signature without declaring the interface, so anything
	 * composing PSR-15 handlers had to take it on faith.
	 */
	public function testTheHandlerIsARealPsr15RequestHandler(): void
	{
		$this->assertInstanceOf(RequestHandlerInterface::class, $this->ctx()->getRequestHandler());
	}

	public function testTheContextAnswersTheSameHandlerEveryTime(): void
	{
		$context = $this->ctx();

		$this->assertSame($context->getRequestHandler(), $context->getRequestHandler());
	}

	public function testContextHandleDelegatesToTheHandler(): void
	{
		$context = $this->ctx();
		$handler = $context->getRequestHandler();

		$context->handle(new ServerRequest('GET', '/delegated'));

		$this->assertNotNull($handler->correlationId());
		$this->assertSame(
			$handler->correlationId(),
			$context->getCorrelationId(),
			'the context reads its correlation id from the handler',
		);
	}

	public function testTheHandlerCanBeDrivenDirectly(): void
	{
		$handler = $this->ctx()->getRequestHandler();

		$response = $handler->handle(new ServerRequest('GET', '/direct'));

		$this->assertNotSame('', $response->getHeaderLine('X-Correlation-Id'));
		$this->assertSame($handler->correlationId(), $response->getHeaderLine('X-Correlation-Id'));
	}

	public function testAnInboundCorrelationIdIsAdoptedAndEchoed(): void
	{
		$handler = $this->ctx()->getRequestHandler();

		$response = $handler->handle(
			(new ServerRequest('GET', '/adopt'))->withHeader('X-Correlation-Id', 'upstream-7'),
		);

		$this->assertSame('upstream-7', $handler->correlationId());
		$this->assertSame('upstream-7', $response->getHeaderLine('X-Correlation-Id'));
	}

	public function testEachRequestGetsItsOwnCorrelationId(): void
	{
		$handler = $this->ctx()->getRequestHandler();

		$ids = [];
		for ($i = 0; $i < 3; $i++) {
			$handler->handle(new ServerRequest('GET', '/seq' . $i));
			$ids[] = $handler->correlationId();
		}

		$this->assertCount(3, array_unique($ids));
	}

	/**
	 * Built once and reused for the context's lifetime -- the worker-mode trade the pipeline exists
	 * to make.
	 */
	public function testThePipelineIsBuiltOnceAndReused(): void
	{
		$handler = $this->ctx()->getRequestHandler();

		$this->assertFalse($handler->hasPipeline(), 'nothing is built before it is needed');

		$handler->handle(new ServerRequest('GET', '/first'));
		$pipeline = $handler->pipeline();

		$this->assertTrue($handler->hasPipeline());
		$this->assertInstanceOf(MiddlewarePipeline::class, $pipeline);

		$handler->handle(new ServerRequest('GET', '/second'));
		$this->assertSame($pipeline, $handler->pipeline());
	}

	/**
	 * The seam a catalog change needs: without it, a middleware stack composed before the change is
	 * reused for the context's whole lifetime and the change is never seen.
	 */
	public function testForgetPipelineForcesARebuild(): void
	{
		$handler = $this->ctx()->getRequestHandler();
		$handler->handle(new ServerRequest('GET', '/before'));
		$before = $handler->pipeline();

		$handler->forgetPipeline();

		$this->assertFalse($handler->hasPipeline());
		$this->assertNotSame($before, $handler->pipeline());
	}

	/**
	 * expose=false suppresses the response header but must not stop the id being resolved -- it is
	 * still what every log line for the request is keyed on.
	 */
	public function testExposeFalseSuppressesTheHeaderButKeepsTheId(): void
	{
		Config::set('core.correlation_id.expose', false, true);
		try {
			$handler = $this->ctx()->getRequestHandler();

			$response = $handler->handle(new ServerRequest('GET', '/quiet'));

			$this->assertFalse($response->hasHeader('X-Correlation-Id'));
			$this->assertNotNull($handler->correlationId());
		} finally {
			Config::remove('core.correlation_id.expose');
		}
	}

	public function testAConfiguredHeaderNameIsUsedBothWays(): void
	{
		Config::set('core.correlation_id.header', 'Request-Id', true);
		try {
			$handler = $this->ctx()->getRequestHandler();

			$response = $handler->handle(
				(new ServerRequest('GET', '/named'))->withHeader('Request-Id', 'rid-42'),
			);

			$this->assertSame('rid-42', $handler->correlationId());
			$this->assertSame('rid-42', $response->getHeaderLine('Request-Id'));
		} finally {
			Config::remove('core.correlation_id.header');
		}
	}

	/**
	 * The handler calls this on the way in, so a runtime that serves requests without a reset
	 * between them still persists each one's state. The flush semantics themselves --- who claims it,
	 * and what a claim writes --- are ContextFlushRequestStateTest's subject.
	 */
	public function testBeginRequestArmsTheStateFlush(): void
	{
		$context = $this->ctx();
		$flushed = (new ReflectionObject($context))->getProperty('requestStateFlushed');

		$context->flushRequestState();
		$this->assertTrue($flushed->getValue($context), 'the flush is claimed');

		$context->beginRequest();

		$this->assertFalse($flushed->getValue($context));
	}

	public function testTheAmbientLoggingScopeIsFreshPerRequest(): void
	{
		$context = $this->ctx();
		\Quiote\Logging\LogContext::enrich(['stale' => 'from-a-prior-request']);

		$context->getRequestHandler()->handle(new ServerRequest('GET', '/scope'));

		$scope = \Quiote\Logging\LogContext::current();
		$this->assertArrayNotHasKey('stale', $scope);
		$this->assertSame($context->getCorrelationId(), $scope['rid'] ?? null);
	}

	/**
	 * The helpers are container services now, so their lifetimes are declared scopes rather than
	 * manual nulls in reset().
	 */
	public function testTheExecutionHelpersResolveThroughTheContainer(): void
	{
		$context = $this->ctx();
		$container = $context->getContainer();

		$this->assertSame($context->getActionResolver(), $container->get(ActionResolver::class));
		$this->assertSame($context->getActionResolver(), $container->get('actionResolver'));
		$this->assertSame($context->getAssetRegistry(), $container->get(AssetRegistry::class));
		$this->assertSame($context->getSlotDispatcher(), $container->get(SlotDispatcher::class));
	}

	public function testTheAssetRegistryIsSharedWithinARequestAndDroppedAtTheBoundary(): void
	{
		$context = $this->ctx();

		$registry = $context->getAssetRegistry();
		$this->assertSame($registry, $context->getAssetRegistry(), 'shared by the whole render tree');

		$context->reset();

		$this->assertNotSame($registry, $context->getAssetRegistry(), 'not carried across the boundary');
	}

	/**
	 * The action resolver holds no request state, so it is deliberately process-lifetime and must
	 * survive the boundary that drops the request-scoped ones.
	 */
	public function testTheActionResolverSurvivesTheRequestBoundary(): void
	{
		$context = $this->ctx();
		$resolver = $context->getActionResolver();

		$context->reset();

		$this->assertSame($resolver, $context->getActionResolver());
	}

	public function testTheSlotDispatcherIsRebuiltPerRequest(): void
	{
		$context = $this->ctx();
		$dispatcher = $context->getSlotDispatcher();
		$this->assertSame($dispatcher, $context->getSlotDispatcher());

		$context->reset();

		$this->assertNotSame($dispatcher, $context->getSlotDispatcher());
	}

	/**
	 * An application may rebind any of these. A rebinding to the wrong type is reported here rather
	 * than as a type error in whatever called the accessor.
	 */
	public function testAnAccessorRejectsARebindingOfTheWrongType(): void
	{
		$context = $this->ctx();
		$context->getContainer()->set(AssetRegistry::class, new stdClass(), Container::SCOPE_REQUEST);

		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessage('which is not a');

		$context->getAssetRegistry();
	}
}
