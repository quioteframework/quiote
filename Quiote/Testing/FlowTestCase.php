<?php
namespace Quiote\Testing;

use Quiote\Context;
use Quiote\Request\RequestDataHolder;

/**
 * FlowTestCase is the base class for all flow tests and provides
 * the necessary assertions
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class FlowTestCase extends PhpUnitTestCase implements IFlowTestCase
{
	/**
	 * @var        string the name of the context to use, null for default context
	 */
	protected $contextName = null;
	
	/**
	 * @var        string the fake routing input
	 */
	protected $input;
	
	/**
	 * @var        Response the response after the dispatch call
	 */
	protected $response;
	
	/**
	 * Constructs a test case with the given name.
	 * @param        string $name
	 * @param        array  $data
	 * @param        string $dataName
	 */
	public function __construct($name = NULL, array $data = [], $dataName = '')
	{
		parent::__construct($name, $data, $dataName);
		$this->setRunTestInSeparateProcess(true);
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
	 * dispatch the request
	 * @since        1.0.0
	 */
	public function dispatch($parameters = [])
	{
		$_SERVER['REQUEST_URI'] = $this->getDispatchScriptName() . $this->getRoutingInput();
		$_SERVER['SCRIPT_NAME'] = $this->getDispatchScriptName();
		
		$context = $this->getContext();
		$this->setRequestData($parameters);
		$context->getRequest()->setMethod($this->getRequestMethod());
		
		$controller = $context->getController();
		$controller->setParameter('send_response', false);
		
		$this->response = $controller->dispatch();
	}
	
	protected function setRequestData($data)
	{
		$rd = $this->getContext()->getRequest()->getRequestData();
		if (is_array($data)) {
			$rd->setParameters($data);
		} elseif ($data instanceof RequestDataHolder) {
			$rd->merge($data);
		}
	}
	
	/**
	 * retrieve the name of the dispatcher script
	 * @return       string the dispatcher scriptname set by an annotation, '/index.php' by default
	 * @since        1.0.0
	 */
	protected function getDispatchScriptName()
	{
		$scriptName = null;
		
		$annotations = $this->getAnnotations();
		
		if(!empty($annotations['method']['quioteDispatchScriptName'])) {
			$scriptName = $annotations['method']['quioteDispatchScriptName'][0];
		} elseif(!empty($annotations['class']['quioteDispatchScriptName'])) {
			$scriptName = $annotations['class']['quioteDispatchScriptName'][0];
		} else {
			$scriptName = '/index.php';
		}
		
		return $scriptName;
	}
	
	/**
	 * retrieve the request method for the dispatch call
	 * @return       string the name of the request method, 'Read' by default
	 * @since        1.0.0
	 */
	protected function getRequestMethod()
	{
		$method = null;
		
		$annotations = $this->getAnnotations();
		
		if(!empty($annotations['method']['quioteRequestMethod'])) {
			$method = $annotations['method']['quioteRequestMethod'][0];
		} elseif(!empty($annotations['class']['quioteRequestMethod'])) {
			$method = $annotations['class']['quioteRequestMethod'][0];
		} else {
			$method = 'Read';
		}
		
		return $method;
	}
	
	/**
	 * retrieve the routing input for the dispatch call
	 * @return       string the name of the request method, 'Read' by default
	 * @since        1.0.0
	 */
	protected function getRoutingInput()
	{
		$input = null;
		
		$annotations = $this->getAnnotations();
		
		if(!empty($annotations['method']['quioteRoutingInput'])) {
			$input = $annotations['method']['quioteRoutingInput'][0];
		} elseif(!empty($annotations['class']['quioteRoutingInput'])) {
			$input = $annotations['class']['quioteRoutingInput'][0];
		} elseif(!empty($this->input)) {
			$input = $this->input;
		} else {
			$input = '';
		}
		
		return $input;
	}
	
	/**
	 * assert that the response has a given tag
	 * @see the documentation of PHPUnit's assertTag()
	 * @param        array the matcher describing the tag
	 * @param        string an optional message
	 * @since        1.0.0
	 */
	public function assertResponseHasTag($matcher, $message = '', $isHtml = true)
	{
		$this->assertTag($matcher, $this->response->getContent(), $message, $isHtml);
	}
	
	
	/**
	 * assert that the response does not have a given tag
	 * @see the documentation of PHPUnit's assertTag()
	 * @since        1.0.0
	 */
	public function assertResponseHasNotTag($matcher, $message = '', $isHtml = true)
	{
		$this->assertNotTag($matcher, $this->response->getContent(), $message, $isHtml);
	}
}

?>