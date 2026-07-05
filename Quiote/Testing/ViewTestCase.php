<?php
namespace Quiote\Testing;

use Quiote\Exception\QuioteException;
use Quiote\Response\WebResponse;
use Quiote\Util\Toolkit;
use Quiote\View\View;
use ConstraintViewHandlesOutputType;

/**
 * ViewTestCase is the base class for all view testcases and provides
 * the necessary assertions
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class ViewTestCase extends FragmentTestCase
{
	/**
	 * @var        string the (short) name of the view
	 */
	protected $viewName;
	
	/**
	 * @var        mixed the result of the view execution
	 */
	protected $viewResult;
	
	/**
	 *  creates the view instance for this testcase
	 * @return     View
	 * @since      1.0.0
	 */
	protected function createViewInstance()
	{
		$this->getContext()->getController()->initializeModule($this->moduleName);
		$viewName = $this->normalizeViewName($this->viewName);
		$viewInstance = $this->getContext()->getController()->createViewInstance($this->moduleName, $viewName);
		$viewInstance->initialize($this->container);
		return $viewInstance;
	}
	
	/**
	 *  runs the view instance for this testcase
	 * @param      string $otName the name of the output type to run the view for
	 *                    null for the default output type
	 * @since      1.0.0
	 * @return     void
	*/
	protected function runView($otName = null)
	{
		// Container-based execution removed; directly instantiate view and invoke execute method.
		$view = $this->createViewInstance();
		// Modern request no longer exposes a separate requestData holder; pass parameter array for legacy execute signatures if needed.
		$req = $this->getContext()->getRequest();
		$rd = $req->getParameters('parameters');
		$method = 'execute' . ucfirst($otName ?? $this->getContext()->getController()->getOutputType()->getName());
		if(!is_callable([$view,$method])) { $method = 'execute'; }
		$this->viewResult = $view->$method($rd);
	}
	
	/**
	 * assert that the view handles the given output type
	 * @param      string $method the output type name
	 * @param      boolean $acceptGeneric true if the generic 'execute' method should be accepted as handled
	 * @param      string $message an optional message to display if the test fails
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertHandlesOutputType($method, $acceptGeneric = false, $message = '')
	{
		$viewInstance = $this->createViewInstance();
		$constraint = new ConstraintViewHandlesOutputType($viewInstance, $acceptGeneric);
		
		self::assertThat($method, $constraint, $message);
	}
	
	/**
	 * assert that the view does not handle the given output type
	 * @param      string $method the output type name
	 * @param      boolean $acceptGeneric true if the generic 'execute' method should be accepted as handled
	 * @param      string $message an optional message to display if the test fails
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertNotHandlesOutputType($method, $acceptGeneric = false, $message = '')
	{
		$viewInstance = $this->createViewInstance();
		$constraint = self::logicalNot(new ConstraintViewHandlesOutputType($viewInstance, $acceptGeneric));
		
		self::assertThat($method, $constraint, $message);
	}
	
	/**
	 * assert that the response contains a redirect
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewRedirects($message = 'Failed asserting that the view redirects')
	{
		$response = $this->container->getResponse();
		try {
			$this->assertTrue($response->hasRedirect(), $message);
		} catch (\Exception) {
			$this->fail($message);
		}
	}
	
	/**
	 * assert that the response contains no redirect
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewRedirectsNot($message = 'Failed asserting that the view does not redirect')
	{
		$response = $this->container->getResponse();
		try {
			$this->assertFalse($response->hasRedirect(), $message);
		} catch (\Exception) {
			$this->fail($message);
		}
	}
	
	/**
	 * assert that the response contains the expected redirect
	 * @param      mixed $expected the expected redirect
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewRedirectsTo($expected, $message = 'Failed asserting that the view redirects to the given target.')
	{
		$response = $this->container->getResponse();
		try {
			$this->assertEquals($expected, $response->getRedirect(), $message);
		} catch (\Exception) {
			$this->fail($message);
		}
	}
	
	/**
	 * Assert that the view sets the given content type.
	 * this assertion only works on WebResponse or subclasses
	 * @param      string $expected the expected content type
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewSetsContentType($expected, $message = 'Failed asserting that the view sets the content type "%1$s".')
	{
		$response = $this->container->getResponse();
		
		if(!($response instanceof WebResponse)) {
			$this->fail(sprintf($message . ' (response is not an WebResponse)', $expected));
		}
		$this->assertEquals($expected, $response->getContentType(), sprintf($message, $expected));
	}
	
	/**
	 * Assert that the view sets the given header with the given value.
	 * this response only works on WebResponse and subclasses
	 * @param      string $expected the name of the expected header
	 * @param      string $expectedValue the value of the expected header
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewSetsHeader($expected, $expectedValue = null, $message = 'Failed asserting that the view sets a header named <%1$s> with the value <%2$s>')
	{
		$response = $this->container->getResponse();
		
		if(!($response instanceof WebResponse)) {
			$this->fail(sprintf($message . ' (response is not an WebResponse)', $expected));
		}
		$this->assertEquals($expectedValue, $response->getHttpHeader($expected), sprintf($message, $expected, $expectedValue));
	}
	
	/**
	 * Assert that the view sets the given cookie with the given value.<y></y>
	 * this response only works on WebResponse and subclasses
	 * @param      string $expected the name of the expected cookie
	 * @param      string $expectedValue the value of the expected header
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewSetsCookie($expected, $expectedValue = null, $message = 'Failed asserting that the view sets a cookie named <%1$s> with a value of <%2$s>')
	{
		$response = $this->container->getResponse();
		
		if(!($response instanceof WebResponse)) {
			$this->fail(sprintf($message . ' (response is not an WebResponse)', $expected, var_export($expectedValue, true)));
		}
		$this->assertEquals($expectedValue, $response->getCookie($expected), sprintf($message, $expected, var_export($expectedValue, true)));
	}
	
	/**
	 * assert that the response has the given http status
	 * this assertion only works on WebResponse or subclasses
	 * @param      string $expected the expected http status
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewResponseHasHTTPStatus($expected, $message = 'Failed asserting that the response status is %1$s.')
	{
		$response = $this->container->getResponse();
		
		if(!($response instanceof WebResponse)) {
			$this->fail(sprintf($message . ' (response is not an WebResponse)', $expected));
		}
		$this->assertEquals($expected, $response->getHttpStatusCode(), sprintf($message, $expected));
	}
	
	/**
	 * assert that the response has the given content 
	 * @param      mixed $expected the expected content
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewResponseHasContent($expected, $message = 'Failed asserting that the response has content <%1$s>.')
	{
		$response = $this->container->getResponse();
		$this->assertEquals($expected, $response->getContent(), sprintf($message, $expected));
	}
	
	/**
	 * assert that the view result has the given content 
	 * @param      mixed $expected the expected content
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewResultEquals($expected, $message = 'Failed asserting the expected view result.')
	{
		$this->assertEquals($expected, $this->viewResult, sprintf($message, $expected));
	}
	
	/**
	 * assert that the view forwards to the given module/action
	 * @param      string $expectedModule the expected module name
	 * @param      string $expectedAction the expected action name
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertViewForwards($expectedModule, $expectedAction, $message = 'Failed asserting that the view forwards to "%1$s" "%2$s".')
	{
		if(!is_object($this->viewResult)) {
			$this->fail('View result not an object; cannot assert forward.');
		}
		$mod = method_exists($this->viewResult,'getModuleName') ? $this->viewResult->getModuleName() : null;
		$act = method_exists($this->viewResult,'getActionName') ? $this->viewResult->getActionName() : null;
		$this->assertEquals($expectedModule, $mod, sprintf($message, $expectedModule, $expectedAction));
		$this->assertEquals(Toolkit::canonicalName($expectedAction), $act, sprintf($message, $expectedModule, $expectedAction));
	}
	
	/**
	 * assert that the view has the  given layer
	 * @param      string $expectedLayer the expected layer name
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertHasLayer($expectedLayer, $message = 'Failed asserting that the view contains the layer "%1$s".')
	{
		$viewInstance = $this->container?->getViewInstance();
		$layer = $viewInstance->getLayer($expectedLayer);
		
		if(null === $layer) {
			$this->fail(sprintf($message, $expectedLayer));
		}
	}
	
	/**
	 * assert that the view has the  given layer
	 * @param      string $expectedLayer the expected layer name
	 * @param      string $message the message to emit on failure
	 * @since      1.0.0
	 * @return     void
	*/
	protected function assertNotHasLayer($expectedLayer, $message = '')
	{
		$viewInstance = $this->container?->getViewInstance();
		$layer = $viewInstance->getLayer($expectedLayer);
		
		if(null !== $layer) {
			$this->fail('Failed asserting that the view does not contain the layer.');
		}
	}
}

?>