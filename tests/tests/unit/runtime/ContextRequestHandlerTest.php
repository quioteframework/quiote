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
	 * The context serves requests through a PSR-15 handler, so anything composing handlers can take
	 * one straight from it.
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

	public function testTheContextReadsItsCorrelationIdFromTheHandler(): void
	{
		$context = $this->ctx();
		$handler = $this->requestHandlerOf($context);

		$context->getRequestHandler()->handle(new ServerRequest('GET', '/delegated'));

		$this->assertNotNull($handler->correlationId());
		$this->assertSame(
			$handler->correlationId(),
			$context->getCorrelationId(),
			'the context reads its correlation id from the handler',
		);
	}

	public function testTheHandlerCanBeDrivenDirectly(): void
	{
		$handler = $this->requestHandlerOf($this->ctx());

		$response = $handler->handle(new ServerRequest('GET', '/direct'));

		$this->assertNotSame('', $response->getHeaderLine('X-Correlation-Id'));
		$this->assertSame($handler->correlationId(), $response->getHeaderLine('X-Correlation-Id'));
	}

	public function testAnInboundCorrelationIdIsAdoptedAndEchoed(): void
	{
		$handler = $this->requestHandlerOf($this->ctx());

		$response = $handler->handle(
			(new ServerRequest('GET', '/adopt'))->withHeader('X-Correlation-Id', 'upstream-7'),
		);

		$this->assertSame('upstream-7', $handler->correlationId());
		$this->assertSame('upstream-7', $response->getHeaderLine('X-Correlation-Id'));
	}

	public function testEachRequestGetsItsOwnCorrelationId(): void
	{
		$handler = $this->requestHandlerOf($this->ctx());

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
		$handler = $this->requestHandlerOf($this->ctx());

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
		$handler = $this->requestHandlerOf($this->ctx());
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
			$handler = $this->requestHandlerOf($this->ctx());

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
			$handler = $this->requestHandlerOf($this->ctx());

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

		$context->flushRequestState();
		$this->assertTrue(
			$context->getLifecycle()->requestStateFlushClaimed(),
			'the flush is claimed',
		);

		$context->beginRequest();

		$this->assertFalse($context->getLifecycle()->requestStateFlushClaimed());
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

		$this->assertSame($context->getContainer()->get(\Quiote\Execution\ActionResolver::class), $container->get(ActionResolver::class));
		$this->assertSame($context->getContainer()->get(\Quiote\Execution\ActionResolver::class), $container->get('actionResolver'));
		$this->assertSame($context->getContainer()->get(\Quiote\Asset\AssetRegistry::class), $container->get(AssetRegistry::class));
		$this->assertSame($context->getContainer()->get(\Quiote\Execution\SlotDispatcher::class), $container->get(SlotDispatcher::class));
	}

	public function testTheAssetRegistryIsSharedWithinARequestAndDroppedAtTheBoundary(): void
	{
		$context = $this->ctx();

		$registry = $context->getContainer()->get(\Quiote\Asset\AssetRegistry::class);
		$this->assertSame($registry, $context->getContainer()->get(\Quiote\Asset\AssetRegistry::class), 'shared by the whole render tree');

		$context->reset();

		$this->assertNotSame($registry, $context->getContainer()->get(\Quiote\Asset\AssetRegistry::class), 'not carried across the boundary');
	}

	/**
	 * The action resolver holds no request state, so it is deliberately process-lifetime and must
	 * survive the boundary that drops the request-scoped ones.
	 */
	public function testTheActionResolverSurvivesTheRequestBoundary(): void
	{
		$context = $this->ctx();
		$resolver = $context->getContainer()->get(\Quiote\Execution\ActionResolver::class);

		$context->reset();

		$this->assertSame($resolver, $context->getContainer()->get(\Quiote\Execution\ActionResolver::class));
	}

	public function testTheSlotDispatcherIsRebuiltPerRequest(): void
	{
		$context = $this->ctx();
		$dispatcher = $context->getContainer()->get(\Quiote\Execution\SlotDispatcher::class);
		$this->assertSame($dispatcher, $context->getContainer()->get(\Quiote\Execution\SlotDispatcher::class));

		$context->reset();

		$this->assertNotSame($dispatcher, $context->getContainer()->get(\Quiote\Execution\SlotDispatcher::class));
	}

	/**
	 * An application may rebind any of these, including to the wrong type. `Context`'s accessors used
	 * to type-check the container's answer on the way past; with the accessors gone that check lives in
	 * the container itself, which is the one place every resolution passes through -- so a consumer
	 * asking for a class name never has to defend against getting something else.
	 */
	public function testAMisboundServiceIsRefusedByTheContainer(): void
	{
		$context = $this->ctx();
		$context->getContainer()->set(AssetRegistry::class, new stdClass(), Container::SCOPE_REQUEST);

		$this->expectException(\Quiote\DI\ContainerException::class);
		$this->expectExceptionMessage('which is not a');

		$context->getContainer()->get(AssetRegistry::class);
	}


	/**
	 * The context's request handler, narrowed to the concrete one whose pipeline and correlation id
	 * these tests inspect. The getter answers the PSR contract, which does not carry either.
	 */
	private function requestHandlerOf(Context $context): ContextRequestHandler
	{
		$handler = $context->getRequestHandler();
		$this->assertInstanceOf(ContextRequestHandler::class, $handler);

		return $handler;
	}

}
