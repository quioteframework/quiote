<?php

use Quiote\Testing\UnitTestCase;
use Quiote\View\View;
use Quiote\Request\WebRequest;

class SampleView extends View
{
	public function execute(WebRequest $rd): void {}

	/**
	 * @param array<string, mixed> $extensions
	 */
	public function callReturnProblemDetails(int $status, array $extensions = []): string
	{
		return $this->returnProblemDetailsFromValidationIncidents(status: $status, extensions: $extensions);
	}
}

class ViewTest extends UnitTestCase
{
	private SampleView $_v;

	#[\Override]
    public function setUp(): void
	{
		$ctx = $this->getContext();
		$ctx->initialize();

		$this->_v = new SampleView();
		$controller = $ctx->getContainer()->get(\Quiote\Controller\Controller::class);
		$descriptor = new \Quiote\Execution\ActionDescriptor('Test','Test','GET','html', false);
		$init = new \Quiote\Execution\LightweightActionInitContext(
			$ctx,
			$descriptor->module,
			$descriptor->action,
			$descriptor->method,
			$descriptor->outputType,
			new WebRequest(),
			$controller->getGlobalResponse()
		);
		$this->_v->initialize($init);
	}

	public function testInitialize(): void
	{
		$ctx = $this->getContext();
		$v = $this->_v;

		$ctx_test = $v->getContext();
		$this->assertSame($ctx, $ctx_test);
	}

	/**
	 * The problem-details helper must put the status it reports in the document onto the
	 * response as well, including the 4xx codes conventional for API validation failures.
	 */
	public function testReturnProblemDetailsAppliesTheStatusToTheResponse(): void
	{
		$response = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class)->getGlobalResponse();

		$json = $this->_v->callReturnProblemDetails(422);

		$this->assertEquals('422', $response->getHttpStatusCode());
		$this->assertSame(\Quiote\Http\ProblemDetails::MEDIA_TYPE, $response->getContentType());

		$decoded = json_decode($json, true);
		$this->assertIsArray($decoded);
		$this->assertSame(422, $decoded['status']);
	}

	public function testReturnProblemDetailsAppliesRateLimitStatus(): void
	{
		$response = $this->getContext()->getContainer()->get(\Quiote\Controller\Controller::class)->getGlobalResponse();

		$this->_v->callReturnProblemDetails(429);

		$this->assertEquals('429', $response->getHttpStatusCode());
	}
}
?>
