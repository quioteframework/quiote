<?php

use Quiote\Response\WebResponse;
use Quiote\Testing\ViewTestCase;
use Quiote\View\View;

/**
 * The seams `ViewTestCase` gives an application's test suite: building the view
 * under test, reaching the response it writes to, and the assertion helpers
 * over that response.
 *
 * A test case extending the class under test rather than instantiating one:
 * these are protected helpers meant to be called from a subclass, so calling
 * them that way is the contract. The subject is the sandbox app's
 * `ControllerTests/SimpleActionSuccess` view, which handles Html only.
 */
class ViewTestCaseTest extends ViewTestCase
{
	protected $moduleName = 'ControllerTests';
	protected $viewName = 'SimpleActionSuccess';

	// --- building the view -------------------------------------------------

	public function testCreateViewInstanceBuildsTheNamedView(): void
	{
		$view = $this->createViewInstance();

		$this->assertInstanceOf(View::class, $view);
		$this->assertStringContainsString('SimpleActionSuccess', $view::class);
	}

	/**
	 * Each call builds a fresh view, so one test's attributes cannot reach the
	 * next assertion in the same test case.
	 */
	public function testCreateViewInstanceBuildsAFreshViewEachTime(): void
	{
		$this->assertNotSame($this->createViewInstance(), $this->createViewInstance());
	}

	public function testGetViewResponseIsTheControllersGlobalResponse(): void
	{
		$response = $this->getViewResponse();

		$this->assertInstanceOf(WebResponse::class, $response);
		$this->assertSame(
			$this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class)->getGlobalResponse(),
			$response,
		);
	}

	// --- output-type assertions --------------------------------------------

	public function testAssertHandlesOutputTypePassesForAnImplementedOutputType(): void
	{
		$this->assertHandlesOutputType('Html');
	}

	public function testAssertNotHandlesOutputTypePassesForAnUnimplementedOutputType(): void
	{
		$this->assertNotHandlesOutputType('Xml');
	}

	/**
	 * The base View supplies a generic execute(), so accepting the generic
	 * fallback makes every output type count as handled -- which is why it is
	 * opt-in rather than the default.
	 */
	public function testTheGenericFallbackMakesEveryOutputTypeCountAsHandled(): void
	{
		$this->assertHandlesOutputType('Xml', true);
	}

	// --- response assertions -----------------------------------------------

	public function testAssertViewRedirectsNotPassesOnAResponseWithNoRedirect(): void
	{
		$this->getViewResponse()->clearRedirect();

		$this->assertViewRedirectsNot();
	}

	public function testAssertViewRedirectsPassesOnceARedirectIsSet(): void
	{
		$this->getViewResponse()->setRedirect('https://example.com/next');

		$this->assertViewRedirects();
		$this->assertViewRedirectsTo('https://example.com/next');
	}

	public function testAssertViewSetsContentTypeReadsTheResponseContentType(): void
	{
		$this->getViewResponse()->setContentType('application/json');

		$this->assertViewSetsContentType('application/json');
	}

	public function testAssertViewSetsHeaderReadsTheResponseHeader(): void
	{
		$this->getViewResponse()->setHttpHeader('X-Quiote-Test', 'set');

		$this->assertViewSetsHeader('X-Quiote-Test', 'set');
	}

	public function testAssertViewSetsCookieReadsTheResponseCookie(): void
	{
		$this->getViewResponse()->setCookie('quiote_test', 'baked');

		$this->assertViewSetsCookie('quiote_test', 'baked');
	}

	public function testAssertViewResponseHasHttpStatusReadsTheResponseStatus(): void
	{
		$this->getViewResponse()->setHttpStatusCode(418);

		$this->assertViewResponseHasHTTPStatus('418');
	}

	public function testAssertViewResponseHasContentReadsTheResponseContent(): void
	{
		$this->getViewResponse()->setContent('rendered output');

		$this->assertViewResponseHasContent('rendered output');
	}

	// --- running the view ---------------------------------------------------

	/**
	 * The view is handed the request itself, the way the pipeline invokes it.
	 * Handing it the request's parameter array instead is a type error against
	 * any view whose execute method is typed for a WebRequest -- which is all
	 * of them.
	 */
	public function testRunViewInvokesTheOutputTypeMethodWithTheRequest(): void
	{
		$this->runView('Html');

		// executeHtml() returns void, so reaching here at all is the point: handing the view
		// anything but a WebRequest is a TypeError before the method body ever runs.
		$this->assertNull($this->viewResult);
	}

	/**
	 * An output type the view has no method for falls back to the generic
	 * execute(), rather than failing to dispatch. The sandbox's base view
	 * answers that fallback by refusing the output type, which is how we can
	 * see which method ran.
	 */
	public function testRunViewFallsBackToTheGenericExecuteMethod(): void
	{
		$this->expectException(\Quiote\Exception\ViewException::class);
		$this->expectExceptionMessageMatches('/does not implement an "executeHtml\(\)" method/');

		$this->runView('Xml');
	}

	// --- view result --------------------------------------------------------

	public function testAssertViewResultEqualsComparesTheRecordedResult(): void
	{
		$this->viewResult = 'Success';

		$this->assertViewResultEquals('Success');
	}

	/**
	 * A view that returns a forward descriptor is asserted on by module and
	 * action, with the action name canonicalised the way the controller
	 * resolves it.
	 */
	public function testAssertViewForwardsReadsTheModuleAndActionOffTheResult(): void
	{
		$this->viewResult = new class {
			public function getModuleName(): string
			{
				return 'ControllerTests';
			}

			public function getActionName(): string
			{
				return 'SimpleAction';
			}
		};

		$this->assertViewForwards('ControllerTests', 'SimpleAction');
	}

	public function testAssertViewForwardsFailsWhenTheResultIsNotAnObject(): void
	{
		$this->viewResult = 'Success';

		try {
			$this->assertViewForwards('ControllerTests', 'SimpleAction');
			$this->fail('a non-object view result cannot describe a forward');
		} catch (\PHPUnit\Framework\AssertionFailedError $e) {
			$this->assertStringContainsString('cannot assert forward', $e->getMessage());
		}
	}

	// --- layers -------------------------------------------------------------

	public function testAssertNotHasLayerPassesForALayerTheViewNeverAdded(): void
	{
		$this->assertNull($this->createViewInstance()->getLayer('NoSuchLayer'));

		$this->assertNotHasLayer('NoSuchLayer');
	}

	public function testAssertHasLayerFailsForALayerTheViewNeverAdded(): void
	{
		try {
			$this->assertHasLayer('NoSuchLayer');
			$this->fail('a layer the view never added must not assert as present');
		} catch (\PHPUnit\Framework\AssertionFailedError $e) {
			$this->assertStringContainsString('NoSuchLayer', $e->getMessage());
		}
	}
}
