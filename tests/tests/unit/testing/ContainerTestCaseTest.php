<?php

use Quiote\Controller\Controller;
use Quiote\Request\RequestState;
use Quiote\Response\WebResponse;
use Quiote\Testing\ContainerTestCase;

/**
 * The seam `ContainerTestCase` gives an application's test suite: `execute()`
 * seeds the current request with the given arguments and exposes the global
 * response as `$this->response`.
 *
 * A test case extending the class under test rather than instantiating one:
 * `execute()` is meant to be called from a subclass, so calling it that way is
 * the contract.
 */
class ContainerTestCaseTest extends ContainerTestCase
{
	public function testExecuteSeedsTheRequestWithTheGivenArguments(): void
	{
		$this->execute(['container_test_case' => 'seeded']);

		$this->assertSame(
			'seeded',
			$this->currentRequest()->getParameter('container_test_case'),
		);
	}

	public function testExecuteSeedsEveryGivenArgument(): void
	{
		$this->execute(['first' => '1', 'second' => '2']);

		$request = $this->currentRequest();
		$this->assertSame('1', $request->getParameter('first'));
		$this->assertSame('2', $request->getParameter('second'));
	}

	/**
	 * The published request has to be the one later reads see, or a fragment
	 * under test runs without the arguments the test gave it.
	 */
	public function testSeededArgumentsSurviveIntoTheNextRead(): void
	{
		$this->execute(['sticky' => 'yes']);

		$this->assertSame('yes', $this->currentRequest()->getParameter('sticky'));
		$this->assertSame('yes', $this->currentRequest()->getParameter('sticky'));
	}

	public function testExecuteExposesTheGlobalResponse(): void
	{
		$this->execute();

		$this->assertInstanceOf(WebResponse::class, $this->response);
		$this->assertSame(
			$this->getContext()->getContainer()->get(Controller::class)->getGlobalResponse(),
			$this->response,
		);
	}

	/** No arguments is the common case: run the fragment against the request as it stands. */
	public function testExecuteWithoutArgumentsStillProducesAResponse(): void
	{
		$this->execute();

		$this->assertInstanceOf(WebResponse::class, $this->response);
	}

	/**
	 * An explicit null means "nothing to seed" rather than an error, so the
	 * response is still made available.
	 */
	public function testAnExplicitNullSeedsNothingAndStillProducesAResponse(): void
	{
		$this->execute(null);

		$this->assertInstanceOf(WebResponse::class, $this->response);
	}

	private function currentRequest(): \Quiote\Request\WebRequest
	{
		return $this->getContext()->getContainer()->get(RequestState::class)->current();
	}
}
