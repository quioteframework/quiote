<?php

use Quiote\Context;
use Quiote\Logging\Log;
use Quiote\Plugin\PluginManager;
use Quiote\ContextLifecycle;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The guarantee this class exists for -- every clear runs, even when one throws -- was previously
 * only reachable by reflecting into a Context and installing a faulty component. Here it is a
 * direct assertion.
 */
#[IsolationEnvironment('testing')]
class ContextLifecycleTest extends PhpUnitTestCase
{
	private function logger(): \Quiote\Logging\CategoryLogger
	{
		return Log::create(self::class);
	}

	public function testStepsRunInRegistrationOrder(): void
	{
		$cleanup = new ContextLifecycle();
		$order = [];
		$cleanup->onRequestEnd('first', static function () use (&$order): void { $order[] = 'first'; });
		$cleanup->onRequestEnd('second', static function () use (&$order): void { $order[] = 'second'; });
		$cleanup->onRequestEnd('third', static function () use (&$order): void { $order[] = 'third'; });

		$cleanup->endRequest($this->logger());

		$this->assertSame(['first', 'second', 'third'], $order);
	}

	/**
	 * The whole point. A half-cleared context that keeps request N's authenticated user installed
	 * serves request N+1 as that user.
	 */
	public function testEveryStepRunsEvenWhenAnEarlierOneThrows(): void
	{
		$cleanup = new ContextLifecycle();
		$ran = [];
		$cleanup->onRequestEnd('before', static function () use (&$ran): void { $ran[] = 'before'; });
		$cleanup->onRequestEnd('exploding', static function (): void {
			throw new \RuntimeException('a socket the peer closed');
		});
		$cleanup->onRequestEnd('after', static function () use (&$ran): void { $ran[] = 'after'; });

		$cleanup->endRequest($this->logger());

		$this->assertSame(['before', 'after'], $ran, 'the step after the failure still ran');
	}

	/**
	 * Errors, not only exceptions: a TypeError in one clear is the same class of problem.
	 */
	public function testAThrownErrorDoesNotStopTheRest(): void
	{
		$cleanup = new ContextLifecycle();
		$ran = false;
		$cleanup->onRequestEnd('exploding', static function (): void { throw new \TypeError('bad type'); });
		$cleanup->onRequestEnd('after', static function () use (&$ran): void { $ran = true; });

		$cleanup->endRequest($this->logger());

		$this->assertTrue($ran);
	}

	/**
	 * run() is called from a `finally`, so throwing would replace whatever exception is already in
	 * flight and hide the original cause of the reset failure.
	 */
	public function testEndRequestNeverThrows(): void
	{
		$cleanup = new ContextLifecycle();
		$cleanup->onRequestEnd('exploding', static function (): void { throw new \RuntimeException('boom'); });

		$cleanup->endRequest($this->logger());

		$this->addToAssertionCount(1);
	}

	public function testEveryFailingStepIsToleratedNotJustTheFirst(): void
	{
		$cleanup = new ContextLifecycle();
		$ran = false;
		$cleanup->onRequestEnd('first failure', static function (): void { throw new \RuntimeException('one'); });
		$cleanup->onRequestEnd('second failure', static function (): void { throw new \RuntimeException('two'); });
		$cleanup->onRequestEnd('survivor', static function () use (&$ran): void { $ran = true; });

		$cleanup->endRequest($this->logger());

		$this->assertTrue($ran);
	}

	public function testEndRequestIsRepeatableAcrossRequests(): void
	{
		$cleanup = new ContextLifecycle();
		$count = 0;
		$cleanup->onRequestEnd('counted', static function () use (&$count): void { $count++; });

		$cleanup->endRequest($this->logger());
		$cleanup->endRequest($this->logger());

		$this->assertSame(2, $count, 'the steps are not consumed by running them');
	}

	public function testLabelsReportTheStepsInRunOrder(): void
	{
		$cleanup = new ContextLifecycle();
		$cleanup->onRequestEnd('alpha', static function (): void {});
		$cleanup->onRequestEnd('beta', static function (): void {});

		$this->assertSame(['alpha', 'beta'], $cleanup->labels());
	}

	public function testForgetStepsForgetsEveryStep(): void
	{
		$cleanup = new ContextLifecycle();
		$ran = false;
		$cleanup->onRequestEnd('counted', static function () use (&$ran): void { $ran = true; });

		$cleanup->forgetSteps();
		$cleanup->endRequest($this->logger());

		$this->assertSame([], $cleanup->labels());
		$this->assertFalse($ran);
	}

	public function testEndRequestOnAnEmptyLifecycleIsANoOp(): void
	{
		(new ContextLifecycle())->endRequest($this->logger());

		$this->addToAssertionCount(1);
	}

	/**
	 * The identity clears must come first, so a step registered later -- including a plugin's --
	 * cannot displace them.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAContextRegistersTheIdentityClearsFirst(): void
	{
		$labels = Context::getInstance()->getLifecycle()->labels();

		$this->assertSame(
			['the session bag', 'the user', 'the request'],
			array_slice($labels, 0, 3),
		);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAContextRegistersTheAmbientClearsToo(): void
	{
		$labels = Context::getInstance()->getLifecycle()->labels();

		foreach ([
			'the ambient logging scope',
			'request-scoped container entries',
			'the cache request state',
			'the routing component',
			'the translation manager',
		] as $expected) {
			$this->assertContains($expected, $labels);
		}
	}

	/**
	 * The extension seam: a plugin holding request-scoped state can now hook the boundary, which it
	 * previously had no way to do.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAPluginContributedClearRunsAtTheBoundary(): void
	{
		$ran = 0;
		PluginManager::addRequestEndClear(
			'a plugin per-request cache',
			static function () use (&$ran): void { $ran++; },
		);

		$ctx = Context::getInstance('plugin_boundary_clear');
		$labels = $ctx->getLifecycle()->labels();

		$this->assertContains('a plugin per-request cache', $labels);
		$this->assertSame(
			'a plugin per-request cache',
			end($labels),
			'a plugin clear appends after the framework\'s own',
		);

		$ctx->reset();

		$this->assertSame(1, $ran, 'the contributed clear ran at the boundary');
	}

	/**
	 * A plugin's broken clear must not cost the context its identity clears either.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAThrowingPluginClearDoesNotBreakTheBoundary(): void
	{
		PluginManager::addRequestEndClear(
			'a broken plugin clear',
			static function (): void { throw new \RuntimeException('plugin exploded'); },
		);

		$ctx = Context::getInstance('plugin_boundary_throw');
		$user = $ctx->getContainer()->get(\Quiote\User\User::class);

		$ctx->reset();

		$this->assertNotSame($user, $ctx->getContainer()->get(\Quiote\User\User::class), 'the identity clears still happened');
	}

	/**
	 * Exactly one caller per request persists the session-backed state. A second claim must fail, or
	 * the state is written twice -- and after the response has been emitted, to somewhere nothing
	 * will ever read.
	 */
	public function testTheRequestStateFlushIsClaimedExactlyOncePerRequest(): void
	{
		$lifecycle = new ContextLifecycle();
		$lifecycle->beginRequest();

		$this->assertTrue($lifecycle->claimRequestStateFlush(), 'the first caller wins');
		$this->assertFalse($lifecycle->claimRequestStateFlush());
		$this->assertFalse($lifecycle->claimRequestStateFlush());
		$this->assertTrue($lifecycle->requestStateFlushClaimed());
	}

	public function testANewLifecycleStartsArmed(): void
	{
		$lifecycle = new ContextLifecycle();

		$this->assertFalse($lifecycle->requestStateFlushClaimed());
		$this->assertTrue($lifecycle->claimRequestStateFlush());
	}

	/**
	 * The case beginRequest() exists for: a runtime that serves requests without ending one between
	 * them would otherwise leave the second request's flush unclaimable, and its state unwritten.
	 */
	public function testBeginRequestReArmsTheClaim(): void
	{
		$lifecycle = new ContextLifecycle();
		$lifecycle->claimRequestStateFlush();

		$lifecycle->beginRequest();

		$this->assertFalse($lifecycle->requestStateFlushClaimed());
		$this->assertTrue($lifecycle->claimRequestStateFlush(), 'claimable again');
	}

	public function testEndRequestReArmsTheClaimForTheNextRequest(): void
	{
		$lifecycle = new ContextLifecycle();
		$lifecycle->claimRequestStateFlush();

		$lifecycle->endRequest($this->logger());

		$this->assertFalse($lifecycle->requestStateFlushClaimed());
	}

	/**
	 * The re-arm has to happen after every clear, so a clear that consults the claim does not see a
	 * fresh request that has not happened yet.
	 */
	public function testEveryClearStillSeesTheClaimAsHeld(): void
	{
		$lifecycle = new ContextLifecycle();
		$lifecycle->claimRequestStateFlush();
		$observed = [];
		$lifecycle->onRequestEnd('first', static function () use ($lifecycle, &$observed): void {
			$observed[] = $lifecycle->requestStateFlushClaimed();
		});
		$lifecycle->onRequestEnd('last', static function () use ($lifecycle, &$observed): void {
			$observed[] = $lifecycle->requestStateFlushClaimed();
		});

		$lifecycle->endRequest($this->logger());

		$this->assertSame([true, true], $observed);
		$this->assertFalse($lifecycle->requestStateFlushClaimed(), 're-armed only afterwards');
	}

	/**
	 * A clear that throws must not cost the next request its armed claim either.
	 */
	public function testTheClaimIsReArmedEvenWhenAClearThrows(): void
	{
		$lifecycle = new ContextLifecycle();
		$lifecycle->claimRequestStateFlush();
		$lifecycle->onRequestEnd('exploding', static function (): void {
			throw new \RuntimeException('boom');
		});

		$lifecycle->endRequest($this->logger());

		$this->assertFalse($lifecycle->requestStateFlushClaimed());
	}
}
