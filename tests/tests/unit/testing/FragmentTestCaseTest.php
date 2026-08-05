<?php

use Quiote\Model\Model;
use Quiote\Testing\FragmentTestCase;
use Sandbox\Models\ContextTestSingletonModel;

/**
 * The seams `FragmentTestCase` gives an application's test suite, which the framework's own suite had
 * never exercised -- so `clearSingletonModels()` went on reflecting a `Context` property that model
 * decomposition had moved into {@see \Quiote\Model\ModelLocator}, and the only thing that noticed was
 * an application's suite failing with `Property Quiote\Context::$singletonModelInstances does not
 * exist`.
 *
 * A test case extending the class under test rather than instantiating one: these are protected
 * helpers meant to be called from a subclass, so calling them that way is the contract.
 */
class FragmentTestCaseTest extends FragmentTestCase
{
	public function testClearSingletonModelsDropsTheSharedInstance(): void
	{
		$locator = $this->getContext()->getModelLocator();

		$first = $locator->get('ContextTestSingleton');
		$this->assertInstanceOf(ContextTestSingletonModel::class, $first);
		$this->assertSame($first, $locator->get('ContextTestSingleton'), 'a singleton model is shared');

		$this->clearSingletonModels();

		$this->assertNotSame(
			$first,
			$locator->get('ContextTestSingleton'),
			'a test that mutated a shared model must not hand it to the next one',
		);
	}

	/**
	 * Nothing to drop is not an error: a suite calling this in setUp() runs before anything has
	 * resolved a model.
	 */
	public function testClearSingletonModelsIsSafeWithNothingResolved(): void
	{
		$this->clearSingletonModels();
		$this->clearSingletonModels();

		$this->assertInstanceOf(
			Model::class,
			$this->getContext()->getModelLocator()->get('ContextTestSingleton'),
		);
	}

	public function testApplyRequestParametersReachesTheCurrentRequest(): void
	{
		$this->applyRequestParameters(['fragment_test_case' => 'applied']);

		$this->assertSame(
			'applied',
			$this->getContext()->getContainer()->get(\Quiote\Request\RequestState::class)
				->current()->getParameter('fragment_test_case'),
		);
	}
}
