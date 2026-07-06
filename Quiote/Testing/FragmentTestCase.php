<?php
namespace Quiote\Testing;

use Quiote\Action\Action;
use Quiote\Context;
use Quiote\Controller\OutputType;
use Quiote\Util\Toolkit;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * FragmentTestCase is the base class for all fragment tests and provides
 * the necessary assertions
 * @since      1.0.0
 * @version    1.0.0
 */
#[RunTestsInSeparateProcesses]
abstract class FragmentTestCase extends PhpUnitTestCase implements IFragmentTestCase
{
	/**
	 * @var        string the name of the context to use, null for default context
	 */
	protected $contextName = null;

	/**
	 * @var string cached request method set by tests (read/write etc). Defaults to 'read'.
	 * Legacy tests previously stored this on the execution container. Since the container
	 * has been removed for the canonical-request refactor we keep the value here and feed
	 * it into the lightweight init context used for action initialization.
	 */
	protected $requestMethod = 'read';
	
	/**
	 * @var        string the name of the action to test
	 */
	protected $actionName;
	
	/**
	 * @var        string the name of the module 
	 */
	protected $moduleName;
	
	/**
	 * @var        bool   the result of the validation process
	 */
	protected $validationSuccess;
	
	/**
	 * @var        mixed legacy execution container (removed) no longer used
	 */
	protected $container;


	/**
	 * previously created an execution container per test; now no-op
	 * @return void
	 * @since      1.0.0
	 */
	public function setUp(): void
	{
		parent::setUp();
		// Provide lightweight shim container to satisfy legacy attribute & validation assertions.
		$this->container = new LightweightTestContainer();
	}
	
	
	/**
	 * unsets legacy container (no longer applicable)
	 * @return void
	 * @since      1.0.0
	 */
	public function tearDown(): void
	{
		$this->container = null; // allow GC
		parent::tearDown();
	}
	
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
	 * normalizes a viewname according to the configured rules
	 * Please do not use this method, it exists only for internal 
	 * purposes and will be removed ASAP. You have been warned
	 * @param      string $shortName the short view name
	 * @return     string the full view name
	 * @since      1.0.0
	 */
	protected function normalizeViewName($shortName)
	{
		$shortName = Toolkit::evaluateModuleDirective(
			$this->moduleName,
			'quiote.view.name',
			[
				'actionName' => $this->actionName,
				'viewName' => $shortName,
			]
		);
		$shortName = Toolkit::canonicalName($shortName);

		return $shortName;
	}

	/**
	 * create an executionfilter for the test
	 * the configured executionfilter class will be wrapped in a testing
	 * extension to provide advanced capabilities required for testing 
	 * only
	 * @return     never
	 * @since      1.0.0
	 */
	protected function createExecutionFilter() { throw new \RuntimeException('Legacy execution filter removed – tests should use middleware pipeline.'); }

	/**
	 * legacy: create a container for the test (removed)
	 * legacy container class reference removed
	 * extension to provide advanced capabilities required for testing
	 * only
	 * @param      mixed $arguments
	 * @param      mixed $outputType
	 * @param      mixed $requestMethod
	 * @return     mixed
	 * @since      1.0.0
	 */
	protected function createExecutionContainer($arguments = null, $outputType = null, $requestMethod = null) { return null; }

	/**
	 * creates an Action instance and initializes it with this testcases
	 * container
	 * @return     Action
	 * @since      1.0.0
	 */
	protected function createActionInstance()
	{
		$actionInstance = $this->getContext()->getController()->createActionInstance($this->moduleName, $this->actionName);
		// Initialize with lightweight init context instead of legacy execution container.
		// Use the request method captured via setRequestMethod (default 'read'). Map Quiote style
		// semantic verbs (read/write/delete) onto HTTP verbs heuristically so downstream code
		// expecting HTTP-like method strings keeps functioning.
		$methodMap = [
			'read' => 'GET',
			'write' => 'POST',
			'delete' => 'DELETE',
			'update' => 'PUT',
		];
		$semantic = strtolower($this->requestMethod);
		$httpMethod = $methodMap[$semantic] ?? strtoupper($semantic);
		// Use the canonical WebRequest (PSR-7) rather than a RequestDataHolder – the init context
		// now expects a ServerRequestInterface. This preserves single-request invariant for tests.
		/** @var \Psr\Http\Message\ServerRequestInterface $canonicalRequest */
		$canonicalRequest = $this->getContext()->getRequest();
		$lw = new \Quiote\Execution\LightweightActionInitContext(
			$this->getContext(),
			$this->moduleName,
			$this->actionName,
			$httpMethod,
			'html',
			$canonicalRequest,
			$this->getContext()->getController()->getGlobalResponse()
		);
		$actionInstance->initialize($lw);
		return $actionInstance;
	}
	
	/**
     * (Deprecated) Legacy helper that previously created an RequestDataHolder instance.
     * Now simply normalizes the provided legacy-style array into a flat parameter array so
     * existing tests that still call createRequestDataHolder(...) continue to function until
     * fully migrated. Accepts either:
     *  - ['foo' => 'bar'] (already normalized)
     *  - [ANY_CONSTANT => ['foo' => 'bar']] legacy style
     * Returns the inner parameter array.
     * @param      array<int|string, mixed> $arguments
     * @param      mixed $type
     * @return     array<int|string, mixed>
     */
    #[\Deprecated(message: 'Will be removed once all tests have been migrated to setArguments([...]).')]
    protected function createRequestDataHolder(array $arguments = [], $type = null)
	{
		// Legacy wrapper style: single top-level element whose value is the parameter array
		if (count($arguments) === 1 && is_array(current($arguments))) {
			$first = current($arguments);
			if (array_is_list($arguments) === false) { // keyed legacy map
				$arguments = $first;
			}
		}
		return $arguments; // already a flat parameter array
	}
	
	
	/**
	 * assert that the exectionContainer has a given attribute with the expected value
	 * @param      mixed $expected the expected attribute value
	 * @param      string $attributeName the attribute name
	 * @param      string $namespace the attribute namespace
	 * @param      string $message an optional message to display if the test fails
	 * @param      float   $delta
	 * @param      integer $maxDepth
	 * @param      boolean $canonicalizeEol
	 * @see        PHPUnit_Framework_Assert::assertEquals()
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertContainerAttributeEquals($expected, $attributeName, $namespace = null, $message = 'Failed asserting that the attribute <%1$s/%2$s> has the value <%3$s>', $delta = 0, $maxDepth = 10, $canonicalizeEol = false)
	{
		$this->assertEquals($expected, $this->container->getAttribute($attributeName, $namespace), sprintf($message, $namespace, $attributeName, $expected));
	}
	
	/**
	 * assert that the exectionContainer has a given attribute 
	 * @param      string $attributeName the attribute name
	 * @param      string $namespace the attribute namespace
	 * @param      string $message an optional message to display if the test fails
	 * @return     void
	 * @since      1.0.0
	 */
	protected function assertContainerAttributeExists($attributeName, $namespace = null, $message = 'Failed asserting that the container has an attribute named <%1$s/%2$s>.')
	{
		$this->assertTrue($this->container->hasAttribute($attributeName, $namespace), sprintf($message, $namespace, $attributeName));
	}
	
	/* --- container delegates --- */

	/**
	 * @see        ExcutionContainer::setOutputType()
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setOutputType(OutputType $outputType)
	{
		$this->container->setOutputType($outputType);
	}

	/**
	 * @see        Request::setRequestData()
	 * @param      mixed $rd
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setRequestData($rd)
	{
		// No-op in modern harness: container no longer tracks separate request data holder.
		// Retained for BC so legacy tests calling setRequestData(...) don't fatally error.
	}
	
	/**
	 * @see        ExcutionContainer::setArguments()
	 * @param      mixed $arguments
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setArguments($arguments)
	{
		// Accept legacy data holder (array) or already normalized array.
		if (is_object($arguments)) {
			// Try common extraction patterns; ignore on failure.
			if (method_exists($arguments, 'getParameters')) {
				try { $maybe = $arguments->getParameters('parameters'); if (is_array($maybe)) { $arguments = $maybe; } } catch(\Throwable) {}
			}
		}
		if (is_array($arguments)) {
			// If legacy wrapper form [ANY_CONSTANT => [..]] convert.
			if (count($arguments) === 1 && is_array(current($arguments))) {
				$arguments = current($arguments);
			}
			$this->container->setArguments($arguments);
		}
	}

	/**
	 * @see        ExcutionContainer::setRequestMethod()
	 * @param      string $method
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setRequestMethod($method)
	{
		// Container has been removed in refactor; keep value locally for createActionInstance().
		$this->requestMethod = $method;
		if($this->container) { // backward compatibility: if a test later introduces a shim container
			try { $this->container->setRequestMethod($method); } catch(\Throwable) { /* ignore */ }
		}
	}

	/**
	 * @see        AttributeHolder::clearAttributes()
	 * @return     void
	 * @since      1.0.0
	 */
	protected function clearAttributes()
	{
		$this->container->clearAttributes();
	}

	/**
	 * @see        AttributeHolder::getAttribute()
	 * @param      string $name
	 * @param      mixed $default
	 * @return     mixed
	 * @since      1.0.0
	 */
	protected function &getAttribute($name, $default = null)
	{
		return $this->container->getAttribute($name, null, $default);
	}

	/**
	 * @see        AttributeHolder::getAttributeNames()
	 * @return     array<int, string>
	 * @since      1.0.0
	 */
	protected function getAttributeNames()
	{
		return $this->container->getAttributeNames();
	}

	/**
	 * @see        AttributeHolder::getAttributes()
	 * @return     array<string, mixed>
	 * @since      1.0.0
	 */
	protected function &getAttributes()
	{
		return $this->container->getAttributes();
	}

	/**
	 * @see        AttributeHolder::hasAttribute()
	 * @param      string $name
	 * @return     bool
	 * @since      1.0.0
	 */
	protected function hasAttribute($name)
	{
		return $this->container->hasAttribute($name);
	}

	/**
	 * @see        AttributeHolder::removeAttribute()
	 * @param      string $name
	 * @return     mixed
	 * @since      1.0.0
	 */
	protected function &removeAttribute($name)
	{
		return $this->container->removeAttribute($name);
	}

	/**
	 * @see        AttributeHolder::setAttribute()
	 * @param      string $name
	 * @param      mixed $value
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setAttribute($name, $value)
	{
		$this->container->setAttribute($name, $value);
	}

	/**
	 * @see        AttributeHolder::appendAttribute()
	 * @param      string $name
	 * @param      mixed $value
	 * @return     void
	 * @since      1.0.0
	 */
	protected function appendAttribute($name, $value)
	{
		$this->container->appendAttribute($name, $value);
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      string $name
	 * @param      mixed $value
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setAttributeByRef($name, &$value)
	{
		$this->container->setAttributeByRef($name, $value);
	}

	/**
	 * @see        AttributeHolder::appendAttributeByRef()
	 * @param      string $name
	 * @param      mixed $value
	 * @return     void
	 * @since      1.0.0
	 */
	protected function appendAttributeByRef($name, &$value)
	{
		$this->container->appendAttributeByRef($name, $value);
	}

	/**
	 * @see        AttributeHolder::setAttributes()
	 * @param      array<string, mixed> $attributes
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setAttributes(array $attributes)
	{
		$this->container->setAttributes($attributes);
	}

	/**
	 * @see        AttributeHolder::setAttributesByRef()
	 * @param      array<string, mixed> $attributes
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setAttributesByRef(array &$attributes)
	{
		$this->container->setAttributesByRef($attributes);
	}

	/**
	 * @return     void
	 */
	protected function clearSingletonModels()
	{
		$context = $this->getContext();
		$reflection = new \ReflectionClass($context);
		$property = $reflection->getProperty('singletonModelInstances');
		// $property->setAccessible(true); // Deprecated, not needed in PHP 8.1+
		$property->setValue($context, []);
	}

	/**
	 * Helper: apply runtime parameters directly to canonical WebRequest (replaces deprecated DataHolder usage).
	 * @param      array<string, mixed> $parameters
	 */
	protected function applyRequestParameters(array $parameters, bool $clearFirst = false): void
	{
		try { $req = $this->getContext()->getRequest(); } catch(\Throwable) { $req = null; }
		if (!$req) { return; }
		if ($clearFirst) {
			$req = $req->clearParameters();
		}
		foreach ($parameters as $k => $v) {
			$req = $req->setParameter($k, $v);
		}
		$this->getContext()->setRequest($req);
	}
}

?>