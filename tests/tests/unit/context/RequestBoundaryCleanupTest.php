<?php

use Quiote\Context;
use Quiote\Logging\Log;
use Quiote\Plugin\PluginManager;
use Quiote\RequestBoundaryCleanup;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The guarantee this class exists for -- every clear runs, even when one throws -- was previously
 * only reachable by reflecting into a Context and installing a faulty component. Here it is a
 * direct assertion.
 */
#[IsolationEnvironment('testing')]
class RequestBoundaryCleanupTest extends PhpUnitTestCase
{
	private function logger(): \Quiote\Logging\CategoryLogger
	{
		return Log::create(self::class);
	}

	public function testStepsRunInRegistrationOrder(): void
	{
		$cleanup = new RequestBoundaryCleanup();
		$order = [];
		$cleanup->add('first', static function () use (&$order): void { $order[] = 'first'; });
		$cleanup->add('second', static function () use (&$order): void { $order[] = 'second'; });
		$cleanup->add('third', static function () use (&$order): void { $order[] = 'third'; });

		$cleanup->run($this->logger());

		$this->assertSame(['first', 'second', 'third'], $order);
	}

	/**
	 * The whole point. A half-cleared context that keeps request N's authenticated user installed
	 * serves request N+1 as that user.
	 */
	public function testEveryStepRunsEvenWhenAnEarlierOneThrows(): void
	{
		$cleanup = new RequestBoundaryCleanup();
		$ran = [];
		$cleanup->add('before', static function () use (&$ran): void { $ran[] = 'before'; });
		$cleanup->add('exploding', static function (): void {
			throw new \RuntimeException('a socket the peer closed');
		});
		$cleanup->add('after', static function () use (&$ran): void { $ran[] = 'after'; });

		$cleanup->run($this->logger());

		$this->assertSame(['before', 'after'], $ran, 'the step after the failure still ran');
	}

	/**
	 * Errors, not only exceptions: a TypeError in one clear is the same class of problem.
	 */
	public function testAThrownErrorDoesNotStopTheRest(): void
	{
		$cleanup = new RequestBoundaryCleanup();
		$ran = false;
		$cleanup->add('exploding', static function (): void { throw new \TypeError('bad type'); });
		$cleanup->add('after', static function () use (&$ran): void { $ran = true; });

		$cleanup->run($this->logger());

		$this->assertTrue($ran);
	}

	/**
	 * run() is called from a `finally`, so throwing would replace whatever exception is already in
	 * flight and hide the original cause of the reset failure.
	 */
	public function testRunNeverThrows(): void
	{
		$cleanup = new RequestBoundaryCleanup();
		$cleanup->add('exploding', static function (): void { throw new \RuntimeException('boom'); });

		$cleanup->run($this->logger());

		$this->addToAssertionCount(1);
	}

	public function testEveryFailingStepIsToleratedNotJustTheFirst(): void
	{
		$cleanup = new RequestBoundaryCleanup();
		$ran = false;
		$cleanup->add('first failure', static function (): void { throw new \RuntimeException('one'); });
		$cleanup->add('second failure', static function (): void { throw new \RuntimeException('two'); });
		$cleanup->add('survivor', static function () use (&$ran): void { $ran = true; });

		$cleanup->run($this->logger());

		$this->assertTrue($ran);
	}

	public function testRunIsRepeatableAcrossRequests(): void
	{
		$cleanup = new RequestBoundaryCleanup();
		$count = 0;
		$cleanup->add('counted', static function () use (&$count): void { $count++; });

		$cleanup->run($this->logger());
		$cleanup->run($this->logger());

		$this->assertSame(2, $count, 'the steps are not consumed by running them');
	}

	public function testLabelsReportTheStepsInRunOrder(): void
	{
		$cleanup = new RequestBoundaryCleanup();
		$cleanup->add('alpha', static function (): void {});
		$cleanup->add('beta', static function (): void {});

		$this->assertSame(['alpha', 'beta'], $cleanup->labels());
	}

	public function testClearForgetsEveryStep(): void
	{
		$cleanup = new RequestBoundaryCleanup();
		$ran = false;
		$cleanup->add('counted', static function () use (&$ran): void { $ran = true; });

		$cleanup->clear();
		$cleanup->run($this->logger());

		$this->assertSame([], $cleanup->labels());
		$this->assertFalse($ran);
	}

	public function testRunOnAnEmptyCleanupIsANoOp(): void
	{
		(new RequestBoundaryCleanup())->run($this->logger());

		$this->addToAssertionCount(1);
	}

	/**
	 * The identity clears must come first, so a step registered later -- including a plugin's --
	 * cannot displace them.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAContextRegistersTheIdentityClearsFirst(): void
	{
		$labels = Context::getInstance()->getRequestBoundaryCleanup()->labels();

		$this->assertSame(
			['the session bag', 'the user', 'the request'],
			array_slice($labels, 0, 3),
		);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAContextRegistersTheAmbientClearsToo(): void
	{
		$labels = Context::getInstance()->getRequestBoundaryCleanup()->labels();

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
		PluginManager::addRequestBoundaryClear(
			'a plugin per-request cache',
			static function () use (&$ran): void { $ran++; },
		);

		$ctx = Context::getInstance('plugin_boundary_clear');
		$labels = $ctx->getRequestBoundaryCleanup()->labels();

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
		PluginManager::addRequestBoundaryClear(
			'a broken plugin clear',
			static function (): void { throw new \RuntimeException('plugin exploded'); },
		);

		$ctx = Context::getInstance('plugin_boundary_throw');
		$user = $ctx->getUser();

		$ctx->reset();

		$this->assertNotSame($user, $ctx->getUser(), 'the identity clears still happened');
	}
}
