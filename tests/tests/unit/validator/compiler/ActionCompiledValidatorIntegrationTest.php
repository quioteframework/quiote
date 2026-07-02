<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Action\Action;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Request\WebRequest;

/**
 * Proves the guarantee from docs/VALIDATOR_COMPILER_PLAN.md end to end for
 * the fluent/compiled path: an action with NO XML validators.xml and NO
 * override of registerValidators() still gets its validators loaded (via
 * Action::registerValidators()'s default CompiledValidatorRegistry wiring)
 * from tests/sandbox/app/Modules/ControllerTests/Validate/CompiledFluentDemo.generated.php,
 * and strict-mode pruning removes any parameter that file didn't declare
 * -- exactly the same guarantee the XML path has always provided. There is
 * no separate, weaker validation story for validators registered this way.
 */
class ActionCompiledValidatorIntegrationTest extends UnitTestCase
{
	private function newAction(): Action
	{
		return new class extends Action {
			public function getDefaultViewName() { return 'Success'; }
			public function executeWrite(WebRequest $req) { return 'Success'; }
			public function handleError(WebRequest $req) { return 'Error'; }
		};
	}

	private function initialize(Action $action, string $method): void
	{
		$ctx = $this->getContext();
		$resp = $ctx->getController()->getGlobalResponse();
		$req = $ctx->getRequest();
		$initContext = new LightweightActionInitContext($ctx, 'ControllerTests', 'CompiledFluentDemo', $method, 'html', $req, $resp);
		$action->initialize($initContext);
	}

	public function testDeclaredParameterSurvivesAndUndeclaredParameterIsPruned()
	{
		$action = $this->newAction();
		$this->initialize($action, 'write');

		$vm = $action->getInitContext()->getValidationManager();
		$action->registerValidators();

		$this->assertCount(1, $vm->getChilds(), 'Expected the compiled fluent validator file to register exactly one validator.');

		$request = $this->newWebRequest([
			'username' => 'alice',
			'unvalidated_extra' => "'; DROP TABLE users; --",
		]);

		$ok = $vm->execute($request);
		$this->assertTrue($ok);

		$pruned = $this->getContext()->getRequest();
		$this->assertTrue($pruned->hasParameter('username'));
		$this->assertSame('alice', $pruned->getParameter('username'));
		$this->assertFalse(
			$pruned->hasParameter('unvalidated_extra'),
			'A parameter with no validator must never survive strict-mode pruning, regardless of whether validators came from XML or a compiled fluent file.'
		);
	}

	public function testValidationFailureIsReportedJustLikeTheXmlPath()
	{
		$action = $this->newAction();
		$this->initialize($action, 'write');

		$vm = $action->getInitContext()->getValidationManager();
		$action->registerValidators();

		$request = $this->newWebRequest(['username' => 'ab']); // shorter than minLength(3)
		$this->assertFalse($vm->execute($request));
	}
}
?>
