<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\OperatorValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

class MyOperatorValidator extends OperatorValidator
{
	public bool $checked = false;

	#[\Override]
    protected function validate(): bool {return true;}
	#[\Override]
    protected function checkValidSetup(): void {$this->checked = true;}
	/** @return array<string, Validator> */
	public function getChildren(): array {return $this->children;}
}

class OperatorValidatorTest extends UnitTestCase
{
	private Context $context;
	private ValidationManager $vm;

	#[\Override]
    public function setUp(): void
	{
		$this->context = $this->getContext();
		$this->vm = $this->context->createInstanceFor('validation_manager');
	}

	public function testShutdown(): void
	{
		$val = $this->vm->createValidator('DummyValidator', []);
		$v = $this->vm->createValidator('MyOperatorValidator', []);
		$v->addChild($val);

		$this->assertFalse($val->shutdown);
		$v->shutdown();
		$this->assertTrue($val->shutdown);
	}

	public function testRegisterValidators(): void
	{
		$val1 = $this->vm->createValidator('DummyValidator', [], [], ['name' => 'val1']);
		$val2 = $this->vm->createValidator('DummyValidator', [], [], ['name' => 'val2']);

		$v = $this->vm->createValidator('MyOperatorValidator', [], [], []);
		$this->assertEquals($v->getChildren(), []);
		$v->registerValidators([$val1, $val2]);
		$this->assertEquals($v->getChildren(), ['val1' => $val1, 'val2' =>$val2]);
	}

	public function testAddChild(): void
	{
		$val = $this->vm->createValidator('DummyValidator', [], [], ['name' => 'val']);
		$v = $this->vm->createValidator('MyOperatorValidator', []);

		$this->assertEquals($v->getChildren(), []);
		$v->addChild($val);
		$this->assertEquals($v->getChildren(), ['val' => $val]);
	}

	public function testExecute(): void
	{
		$v = $this->vm->createValidator('MyOperatorValidator', []);
		$this->assertFalse($v->checked);
		$this->assertEquals($v->execute($this->newWebRequest()), Validator::SUCCESS);
		$this->assertTrue($v->checked);
	}
}
?>
