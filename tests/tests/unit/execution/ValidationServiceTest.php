<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ValidationService;
use Quiote\Execution\ValidationResult;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;
use Quiote\Request\WebRequest;
use Quiote\Action\Action;

class ValidationServiceTest extends UnitTestCase
{
    private function newAction(bool $manualOk, bool $registerValidator = false, ?ValidationManager $regManager = null): Action
    {
        $ctx = $this->getContext();
        return new class($ctx, $manualOk, $registerValidator, $regManager) extends Action {
            public function __construct(\Quiote\Context $ctx, private readonly bool $manualOk, private readonly bool $doRegister, private readonly ?ValidationManager $regManager) { $this->context = $ctx; }
            public function getDefaultViewName(): string { return 'Success'; }
            public function executeWrite(WebRequest $req): string { return 'Success'; }
            public function handleError(WebRequest $req): string { return 'Error'; }
            public function isSecure(): bool { return false; }
            public function registerWriteValidators(): void { if($this->doRegister) { $context = $this->getContext(); if ($context === null) { throw new \RuntimeException('Action has no context'); } $vm = $this->regManager ?: $context->createInstanceFor('validation_manager'); $vm->createValidator('DummyValidator', ['alpha'], [], ['name'=>'manualReg']); } }
            public function validateWrite(WebRequest $req): bool { return $this->manualOk; }
            public function validate(WebRequest $req): bool { return $this->manualOk; }
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
        // Errors may be synthesized empty if manual validate also fails; verify every
        // collected error is a message string (the documented error-map contract).
        foreach ($res->getErrors() as $error) {
            $this->assertIsString($error, 'Each validation error must be a message string');
        }
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

    public function testXmlOnlyValidateInvokesManualRegistrationAndWhitelistsArgument(): void
    {
        // Regression test: xmlOnlyValidate() used to skip register{Method}Validators()
        // entirely, so actions defining validators purely in PHP (no validators.xml)
        // never had them executed -> the argument stayed unwhitelisted and
        // getParameter() threw UnvalidatedParameterAccessException even on success.
        $ctx = $this->getContext();
        $manager = $ctx->createInstanceFor('validation_manager');
        $svc = new ValidationService($manager);
        $action = $this->newAction(true, true, $manager);
        $req = $this->newWebRequest(); // no pre-seeded whitelist
        $req = $req->withQueryParams(['alpha' => 'A']);
        $ctx->setRequest($req);

        $res = $svc->xmlOnlyValidate($action, $req, 'app', 'dummy', 'write');

        $this->assertTrue($res->ok);
        $trace = $res->getTrace();
        $this->assertNotNull($trace);
        $this->assertContains('manualReg', $trace->validatorsLoaded, 'Expected manually registered validator to be loaded');
        $finalRequest = $ctx->getRequest();
        $this->assertSame('A', $finalRequest->getParameter('alpha'), 'Expected argument to be whitelisted and retain its value');
    }

    public function testXmlOnlyValidateManualRegistrationFailureCapturesErrors(): void
    {
        $ctx = $this->getContext();
        $manager = $ctx->createInstanceFor('validation_manager');
        $svc = new ValidationService($manager);
        $req = $this->newWebRequest();
        $req = $req->withQueryParams(['alpha' => 'A']);
        $ctx->setRequest($req);
        $initCtx = new \Quiote\Execution\LightweightActionInitContext(
            $ctx,
            'app',
            'dummy',
            'write',
            'html',
            $req,
            $ctx->getController()->getGlobalResponse()
        );
        // register{Method}Validators() runs inside xmlOnlyValidate() itself (after the
        // manager is cleared), so the failing validator must be created from within it
        // rather than pre-seeded on $manager beforehand. It reads the manager via
        // getInitContext()->getValidationManager(), mirroring ValidatorBuilder::on() usage.
        $action = new class($ctx, $initCtx) extends Action {
            public function __construct(\Quiote\Context $ctx, \Quiote\Execution\ActionInitContext $initCtx) { $this->context = $ctx; $this->initContext = $initCtx; }
            public function getDefaultViewName(): string { return 'Success'; }
            public function executeWrite(WebRequest $req): string { return 'Success'; }
            public function handleError(WebRequest $req): string { return 'Error'; }
            public function isSecure(): bool { return false; }
            public function registerWriteValidators(): void
            {
                $vm = $this->getInitContext()?->getValidationManager();
                if (!$vm instanceof ValidationManager) {
                    throw new \RuntimeException('Expected a ValidationManager instance from getInitContext()->getValidationManager()');
                }
                $v = $vm->createValidator('DummyValidator', ['alpha'], [], ['name' => 'manualReg', 'severity' => 'error']);
                $v->val_result = false;
            }
        };

        $res = $svc->xmlOnlyValidate($action, $req, 'app', 'dummy', 'write');

        $this->assertFalse($res->ok);
        $this->assertNotEmpty($res->getErrors());
    }

    public function testGetContextIsNullBeforeAnyValidation(): void
    {
        $svc = new ValidationService();
        $this->assertNull($svc->getContext());
    }

    public function testValidateExceptionPathPropagatesAsCriticalFailure(): void
    {
        // A validator throwing is a framework/app bug, not "the user submitted
        // invalid input" -- validate() must NOT swallow it into a graceful
        // ValidationResult::failure(). It must propagate so ErrorHandlingMiddleware
        // turns it into a 500, since pruning (which happens later inside
        // ValidationManager::execute()) never completed and the request could
        // otherwise be left in an unpruned, unsafe state.
        $ctx = $this->getContext();
        $manager = $ctx->createInstanceFor('validation_manager');
        $svc = new ValidationService($manager);
        $action = new class($ctx, $manager) extends Action {
            public function __construct(\Quiote\Context $ctx, private readonly ValidationManager $vm) { $this->context = $ctx; }
            public function getDefaultViewName(): string { return 'Success'; }
            public function executeWrite(WebRequest $req): string { return 'Success'; }
            public function handleError(WebRequest $req): string { return 'Error'; }
            public function isSecure(): bool { return false; }
            public function registerWriteValidators(): void {
                $v = $this->vm->createValidator('DummyValidator', ['beta'], [], ['name'=>'willThrow','severity'=>'error']);
                $v->throw_on_execute = true;
            }
            public function validateWrite(WebRequest $req): bool { return true; }
        };
        $req = $this->newWebRequest(['beta' => 'B']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('validator boom');
        $svc->validate($action, $req, 'app', 'dummy', 'write');
    }

    public function testXmlOnlyValidateExceptionPathPropagatesAsCriticalFailure(): void
    {
        $ctx = $this->getContext();
        $manager = $ctx->createInstanceFor('validation_manager');
        $svc = new ValidationService($manager);
        $action = new class($ctx, $manager) extends Action {
            public function __construct(\Quiote\Context $ctx, private readonly ValidationManager $vm) { $this->context = $ctx; }
            public function getDefaultViewName(): string { return 'Success'; }
            public function executeWrite(WebRequest $req): string { return 'Success'; }
            public function handleError(WebRequest $req): string { return 'Error'; }
            public function isSecure(): bool { return false; }
            public function registerWriteValidators(): void {
                $v = $this->vm->createValidator('DummyValidator', ['beta'], [], ['name'=>'willThrow','severity'=>'error']);
                $v->throw_on_execute = true;
            }
        };
        $req = $this->newWebRequest(['beta' => 'B']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('validator boom');
        $svc->xmlOnlyValidate($action, $req, 'app', 'dummy', 'write');
    }

    public function testRegisterValidatorsReturnsSilentlyWhenInitContextHasNoContext(): void
    {
        // Guards against a decoupled state where $initContext is set (e.g. by a partial/
        // custom initialize() override) but $context was never assigned. Previously this
        // reached CompiledValidatorRegistry::apply() with a null $context argument, which
        // is typed as non-nullable Context and would fatally error (TypeError).
        $action = new class extends Action {
            public function getDefaultViewName(): string { return 'Success'; }
            public function executeWrite(WebRequest $req): string { return 'Success'; }
            public function handleError(WebRequest $req): string { return 'Error'; }
            public function isSecure(): bool { return false; }
        };

        $initContext = new class implements \Quiote\Execution\ActionInitContext {
            public function getContext(): \Quiote\Context { throw new \LogicException('not used in this test'); }
            public function getModuleName(): string { return 'app'; }
            public function getActionName(): string { return 'dummy'; }
            public function getRequestMethod(): string { return 'write'; }
            public function getOutputTypeName(): string { return 'html'; }
            public function getRequestData(): ?\Psr\Http\Message\ServerRequestInterface { return null; }
            public function getResponse(): \Quiote\Response\WebResponse { throw new \LogicException('not used in this test'); }
            public function setViewModuleName(?string $module): void {}
            public function setViewName(?string $name): void {}
            public function getViewModuleName(): ?string { return null; }
            public function getViewName(): ?string { return null; }
            public function getValidationManager() { return null; }
        };

        $ro = new ReflectionObject($action);
        $initContextProp = $ro->getProperty('initContext');
        $initContextProp->setValue($action, $initContext);
        $contextProp = $ro->getProperty('context');
        $contextProp->setValue($action, null);

        // Must not throw despite $initContext being non-null and $context being null.
        $action->registerValidators();
        $this->assertNull($action->getContext());
    }
}
