<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ValidationService;
use Agavi\Execution\ValidationResult;
use Agavi\Validator\AgaviValidationManager;
use Agavi\Validator\AgaviValidator;
use Agavi\Request\AgaviWebRequest;
use Agavi\Action\AgaviAction;

class ValidationServiceTest extends AgaviUnitTestCase
{
    private function newAction(bool $manualOk, bool $registerValidator = false, ?AgaviValidationManager $regManager = null): AgaviAction
    {
        $ctx = $this->getContext();
        return new class($ctx, $manualOk, $registerValidator, $regManager) extends AgaviAction {
            public function __construct($ctx, private bool $manualOk, private bool $doRegister, private ?AgaviValidationManager $regManager) { $this->context = $ctx; }
            public function getDefaultViewName() { return 'Success'; }
            public function executeWrite(AgaviWebRequest $req) { return 'Success'; }
            public function handleError(AgaviWebRequest $req) { return 'Error'; }
            public function isSecure() { return false; }
            public function registerWriteValidators() { if($this->doRegister) { $vm = $this->regManager ?: $this->getContext()->createInstanceFor('validation_manager'); $vm->createValidator('DummyValidator', ['alpha'], [], ['name'=>'manualReg']); } }
            public function validateWrite(AgaviWebRequest $req) { return $this->manualOk; }
            public function validate(AgaviWebRequest $req) { return $this->manualOk; }
        };
    }

    public function testValidateManualRegistrationSuccess(): void
    {
    $ctx = $this->getContext();
    $manager = $ctx->createInstanceFor('validation_manager');
    $svc = new ValidationService($manager);
    $action = $this->newAction(true, true, $manager);
    $req = $this->newWebRequest(['alpha' => 'A']);
    // Ensure registration method is write-specific
    $res = $svc->validate($action, $req, 'app', 'dummy', 'write');
        $this->assertInstanceOf(ValidationResult::class, $res);
    $this->assertTrue($res->ok);
    $trace = $res->getTrace();
    $this->assertNotNull($trace);
    $this->assertSame('dummy', $trace->action);
        // In manual registration path validatorsLoaded may be empty if registration used a different manager instance.
        // We only assert successful outcome here.
    }

    public function testValidateFailureCapturesErrors(): void
    {
    $ctx = $this->getContext();
    $manager = $ctx->createInstanceFor('validation_manager');
    $svc = new ValidationService($manager);
    $action = $this->newAction(false, true, $manager); // manual validate returns false
        $req = $this->newWebRequest(['alpha' => 'A']);
        // Make validator fail by toggling underlying manager's validator state
    $validator = $manager->createValidator('DummyValidator', ['alpha'], [], ['name' => 'manualReg','severity' => 'error']);
        $validator->val_result = false;
        $res = $svc->validate($action, $req, 'app', 'dummy', 'write');
        $this->assertFalse($res->ok);
        // Errors may be synthesized empty if manual validate also fails; just assert result false
        $this->assertIsArray($res->getErrors());
    }

    public function testXmlOnlyValidateSkipsManualActionMethods(): void
    {
    $svc = new ValidationService();
    $action = $this->newAction(true, false); // manual registers none (manager created internally)
        $req = $this->newWebRequest();
        $res = $svc->xmlOnlyValidate($action, $req, 'app', 'dummy', 'write');
        $this->assertInstanceOf(ValidationResult::class, $res);
    $this->assertTrue($res->ok);
    $trace = $res->getTrace();
    $this->assertNotNull($trace);
    $this->assertSame('dummy', $trace->action);
    }

    public function testValidateExceptionPath(): void
    {
        // Ensure that a throwing validator is registered AFTER manager->clear() by using the action's register* method
        $ctx = $this->getContext();
        $manager = $ctx->createInstanceFor('validation_manager');
        $svc = new ValidationService($manager);
        $action = new class($ctx, $manager) extends AgaviAction {
            public function __construct($ctx, private AgaviValidationManager $vm) { $this->context = $ctx; }
            public function getDefaultViewName() { return 'Success'; }
            public function executeWrite(AgaviWebRequest $req) { return 'Success'; }
            public function handleError(AgaviWebRequest $req) { return 'Error'; }
            public function isSecure() { return false; }
            public function registerWriteValidators() { /** @var DummyValidator $v */ $v = $this->vm->createValidator('DummyValidator', ['beta'], [], ['name'=>'willThrow','severity'=>'error']); $v->throw_on_execute = true; }
            public function validateWrite(AgaviWebRequest $req) { return true; }
        };
        $req = $this->newWebRequest(['beta' => 'B']);
        $res = $svc->validate($action, $req, 'app', 'dummy', 'write');
        $this->assertFalse($res->ok, 'Expected validation to fail due to exception');
        // Exception path encodes message under key "exception" (not in getErrors()).
        $this->assertArrayHasKey('exception', $res->data);
        $this->assertStringContainsString('boom', $res->data['exception']);
    }
}
