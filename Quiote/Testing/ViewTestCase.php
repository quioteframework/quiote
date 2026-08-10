<?php
namespace Quiote\Testing;

use Quiote\Execution\ImmutableViewInitContext;
use Quiote\Exception\QuioteException;
use Quiote\Response\WebResponse;
use Quiote\Util\Toolkit;
use Quiote\View\View;
use Quiote\Testing\PHPUnit\Constraint\ConstraintViewHandlesOutputType;

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
	 *  creates the view instance for this testcase, initializing it with a
	 *  lightweight ImmutableViewInitContext (container-less pipeline).
	 * @return     View
	 * @since      1.0.0
	 */
	protected function createViewInstance()
	{
		$controller = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class);
		$controller->initializeModule($this->moduleName);
		$viewName = $this->normalizeViewName($this->viewName);
		$viewInstance = $controller->createViewInstance($this->moduleName, $viewName);
		$response = $controller->getGlobalResponse();
		$vic = new ImmutableViewInitContext(
			context: $this->getContext(),
			viewModule: $this->moduleName,
			viewName: $viewName,
			outputType: $controller->getOutputType()->getName(),
			actionModule: null,
			actionName: null,
			actionAttributes: [],
			response: $response,
		);
		$viewInstance->initialize($vic);
		return $viewInstance;
	}

	/**
	 * Resolve the response used by the last created view instance.
	 * @return     WebResponse
	 * @since      1.0.0
	 */
	protected function getViewResponse(): WebResponse
	{
		return $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class)->getGlobalResponse();
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
		// The view is handed the request itself, the way the pipeline invokes it (see
		// {@see \Quiote\Execution\ActionExecutor}) -- a view's executeXml()/execute() is typed for
		// a WebRequest, so anything else is a type error rather than a "legacy signature".
		$request = $this->getContext()->getContainer()->get(\Quiote\Request\WebRequest::class);
		$method = 'execute' . ucfirst($otName ?? $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class)->getOutputType()->getName());
		if(!is_callable([$view,$method])) { $method = 'execute'; }
		$this->viewResult = $view->$method($request);
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
		$response = $this->getViewResponse();
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
		$response = $this->getViewResponse();
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
		$response = $this->getViewResponse();
		// getRedirect() answers a {location, code} record; the target being asserted is the location.
		$redirect = $response->getRedirect();

		$this->assertEquals($expected, $redirect['location'] ?? null, $message);
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
		$response = $this->getViewResponse();

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
		$response = $this->getViewResponse();
		// A header is stored as its list of values; compare against the form it is sent in, so a
		// single-valued header asserts as the plain string the caller set.
		$values = $response->getHttpHeader($expected);
		$actual = is_array($values) ? implode(', ', $values) : $values;

		$this->assertEquals($expectedValue, $actual, sprintf($message, $expected, (string) $expectedValue));
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
		$response = $this->getViewResponse();
		// getCookie() answers the whole cookie record (lifetime, path, flags); the value being
		// asserted is the one the view set.
		$cookie = $response->getCookie($expected);
		$actual = is_array($cookie) ? ($cookie['value'] ?? null) : $cookie;

		$this->assertEquals($expectedValue, $actual, sprintf($message, $expected, var_export($expectedValue, true)));
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
		$response = $this->getViewResponse();

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
		$response = $this->getViewResponse();
		$this->assertEquals($expected, $response->getContent(), sprintf($message, var_export($expected, true)));
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
		$this->assertEquals($expected, $this->viewResult, sprintf($message, var_export($expected, true)));
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
		$viewInstance = $this->createViewInstance();
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
		$viewInstance = $this->createViewInstance();
		$layer = $viewInstance->getLayer($expectedLayer);

		if(null !== $layer) {
			$this->fail('Failed asserting that the view does not contain the layer.');
		}
	}
}

?>
