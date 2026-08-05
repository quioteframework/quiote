<?php

use Nyholm\Psr7\ServerRequest;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\User\CurrentUser;
use Quiote\User\ISecurityUser;

/**
 * RequestState and CurrentUser exist for one reason: to be safely injectable into something that
 * outlives a request. That makes "resolves on every call" the property under test, not an
 * implementation detail -- an accessor that memoized would pass every functional test here and
 * still leak one request's identity into the next one in a worker.
 */
#[IsolationEnvironment('testing')]
class RequestScopeAccessorTest extends PhpUnitTestCase
{
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testTheContainerResolvesBothAccessors(): void
	{
		$container = Context::getInstance()->getContainer();

		$this->assertInstanceOf(RequestState::class, $container->get(RequestState::class));
		$this->assertInstanceOf(CurrentUser::class, $container->get(CurrentUser::class));
		$this->assertSame($container->get(RequestState::class), $container->get('requestState'));
		$this->assertSame($container->get(CurrentUser::class), $container->get('currentUser'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testCurrentAnswersTheContextsRequest(): void
	{
		$ctx = Context::getInstance();
		$state = new RequestState($ctx);

		$this->assertSame($ctx->getRequest(), $state->current());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testPublishReplacesTheCurrentRequest(): void
	{
		$ctx = Context::getInstance();
		$state = new RequestState($ctx);
		$replacement = $state->current()->withAttribute('published.marker', 'yes');

		$state->publish($replacement);

		$this->assertSame($replacement, $state->current());
		$this->assertSame($replacement, $ctx->getRequest(), 'the context sees it too');
		$this->assertSame('yes', $state->current()->getAttribute('published.marker'));
	}

	/**
	 * A foreign PSR-7 request can arrive from middleware or a test. current() must still answer a
	 * WebRequest, or every consumer would have to check.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testPublishNormalizesAForeignPsrRequest(): void
	{
		$state = new RequestState(Context::getInstance());

		$state->publish(new ServerRequest('GET', '/foreign'));

		$this->assertInstanceOf(WebRequest::class, $state->current());
		$this->assertSame('/foreign', $state->current()->getUri()->getPath());
	}

	/**
	 * The reason the class exists. A holder that captured the request at construction would answer
	 * the pre-mutation one; this must answer whatever was published since.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testRequestStateResolvesPerCallRatherThanMemoizing(): void
	{
		$ctx = Context::getInstance();
		$state = new RequestState($ctx);

		$first = $state->current();
		$second = $first->withAttribute('generation', 2);
		$state->publish($second);
		$third = $second->withAttribute('generation', 3);
		$state->publish($third);

		$this->assertNotSame($first, $state->current(), 'not the construction-time request');
		$this->assertSame($third, $state->current());
		$this->assertSame(3, $state->current()->getAttribute('generation'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testCurrentUserAnswersTheContextsUser(): void
	{
		$ctx = Context::getInstance();

		$this->assertSame($ctx->getContainer()->get(\Quiote\User\User::class), (new CurrentUser($ctx))->get());
	}

	/**
	 * The cross-request identity leak, exactly as it would happen in a worker: an accessor built
	 * during request N must answer request N+1's user after the boundary reset, not the one it saw
	 * first.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testCurrentUserResolvesPerCallAcrossARequestBoundary(): void
	{
		$ctx = Context::getInstance();
		$accessor = new CurrentUser($ctx);

		$firstRequestUser = $accessor->get();
		$this->assertSame($firstRequestUser, $accessor->get(), 'stable within one request');

		// What WorkerManager does between requests.
		$ctx->reset();

		$secondRequestUser = $accessor->get();
		$this->assertNotSame(
			$firstRequestUser,
			$secondRequestUser,
			'a memoizing accessor would serve the previous request\'s user here',
		);
		$this->assertSame($ctx->getContainer()->get(\Quiote\User\User::class), $secondRequestUser);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testIsAuthenticatedReflectsTheCurrentUser(): void
	{
		$ctx = Context::getInstance();
		$accessor = new CurrentUser($ctx);
		$user = $ctx->getContainer()->get(\Quiote\User\User::class);

		if (!$user instanceof ISecurityUser) {
			$this->assertFalse(
				$accessor->isAuthenticated(),
				'a user with no security surface is not authenticated',
			);

			return;
		}

		$user->setAuthenticated(false);
		$this->assertFalse($accessor->isAuthenticated());

		$user->setAuthenticated(true);
		$this->assertTrue($accessor->isAuthenticated(), 'read through, not captured');
	}

	/**
	 * The wiring these accessors exist to make possible. A singleton depending on the request or
	 * the user directly is refused; depending on the accessor is not.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testASingletonMayInjectTheAccessorsButNotTheRequestOrUser(): void
	{
		$container = Context::getInstance()->getContainer();

		$container->set(
			SingletonUsingRequestScopeAccessors::class,
			SingletonUsingRequestScopeAccessors::class,
			Container::SCOPE_SINGLETON,
		);
		$consumer = $container->get(SingletonUsingRequestScopeAccessors::class);
		$this->assertInstanceOf(SingletonUsingRequestScopeAccessors::class, $consumer);

		$container->set(
			SingletonCapturingTheRequest::class,
			SingletonCapturingTheRequest::class,
			Container::SCOPE_SINGLETON,
		);
		$this->expectException(\Quiote\DI\ContainerException::class);
		$container->get(SingletonCapturingTheRequest::class);
	}

	/**
	 * The refusal has to say what to do instead. This is a wiring-time error someone hits once, and
	 * guessing their way out of it is how a captured request ends up behind a factory instead.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testTheCaptiveDependencyRefusalNamesTheAccessorToUseInstead(): void
	{
		$container = Context::getInstance()->getContainer();
		$container->set(
			SingletonCapturingTheRequest::class,
			SingletonCapturingTheRequest::class,
			Container::SCOPE_SINGLETON,
		);

		try {
			$container->get(SingletonCapturingTheRequest::class);
			$this->fail('Expected the captive-dependency guard to refuse this wiring.');
		} catch (\Quiote\DI\ContainerException $e) {
			$this->assertStringContainsString(RequestState::class, $e->getMessage());
			$this->assertStringContainsString('resolves per call', $e->getMessage());
		}
	}
}

/** The sanctioned wiring: a singleton that reaches request-scoped state without capturing it. */
class SingletonUsingRequestScopeAccessors
{
	public function __construct(
		public readonly RequestState $requestState,
		public readonly CurrentUser $currentUser,
	) {
	}
}

/** The wiring the guard exists to refuse. */
class SingletonCapturingTheRequest
{
	public function __construct(public readonly WebRequest $request)
	{
	}
}
