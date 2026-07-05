<?php
namespace Quiote\Testing;

use Quiote\Context;
use Quiote\Request\WebRequest;

/**
 * UnitTestCase is the base class for all unit testcases and provides
 * the necessary assertions
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class UnitTestCase extends PhpUnitTestCase implements IUnitTestCase
{
	/**
	 * @var        string the name of the context to use, null for default context
	 */
	protected $contextName = null;
	
	/**
	 * Return the context defined for this test (or the default one).
	 * @return     Context The context instance defined for this test.
	 * @since      1.0.0
	 */
	public function getContext()
	{
		return Context::getInstance($this->contextName);
	}

	/**
	 * Convenience factory for PSR-compatible WebRequest instances in tests.
	 * @param array<string,mixed> $parameters runtime parameters to seed.
	 * @param string[] $additionalWhitelist additional parameter keys to whitelist.
	 */
	protected function newWebRequest(array $parameters = [], array $additionalWhitelist = []): WebRequest
	{
		$request = new WebRequest();
		$request->initialize($this->getContext());
		// Use withQueryParams() to put params in intrinsic (query) storage rather than
		// runtimeParameters. This ensures that pruneParametersToValidated() can correctly
		// remove unvalidated parameters (runtimeParameters with validatedKeys bypass pruning).
		if($parameters) {
			$request = $request->withQueryParams($parameters);
		}
		// In unit tests we often bypass the validation manager; whitelist seeded keys and any explicitly provided additional whitelist keys (e.g. export targets for failure scenarios).
		$wl = [];
		if($parameters) { $wl = array_keys($parameters); }
		if($additionalWhitelist) { $wl = array_merge($wl, $additionalWhitelist); }
		if($wl) { $request->enforceValidatedParameters(array_values(array_unique($wl))); }
		return $request;
	}
}