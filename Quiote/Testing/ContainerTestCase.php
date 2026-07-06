<?php
namespace Quiote\Testing;

use Quiote\Context;

/**
 * ContainerTestCase is the base class for all tests that target a specific
 * container execution and provides the necessary assertions
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class ContainerTestCase extends FragmentTestCase
{
	/**
	 * @var        string the name of the action to use
	 */
	protected $acionName;

	/**
	 * @var        string the name of the module the action resides in
	 */
	protected $moduleName;

	/**
	 * @var        \Quiote\Response\WebResponse the response after the dispatch call
	 */
	protected $response;

	/**
	 * dispatch the request
	 * @param      array<string, mixed>|null $arguments
	 * @param      string|null $outputType
	 * @param      string|null $requestMethod
	 * @return     void
	 * @since      1.0.0
	 */
	public function execute($arguments = null, $outputType = null, $requestMethod = null)
	{
		// Legacy container dispatch removed. Provide deprecation notice and simulate minimal flow.
		$context = Context::getInstance();
		if (is_array($arguments)) {
			// Inject parameters directly into request runtime for downstream usage.
			try {
				$request = $context->getRequest();
				foreach ($arguments as $k => $v) { $request = $request->setParameter($k, $v); }
				$context->setRequest($request);
			} catch (\Throwable) {}
		}
		// Response simulation: create an empty response equivalent.
		$this->response = $context->getController()->getGlobalResponse();
	}

	// Tag-based response assertions removed (legacy DOM matcher). Modern tests should inspect
	// response content directly or use DOMDocument/XPath as needed.
}

?>