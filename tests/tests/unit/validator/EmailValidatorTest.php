<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\EmailValidator;
use Quiote\Validator\ValidationManager;

class EmailValidatorWrapper extends EmailValidator
{
	protected mixed $data = null;

	public function setData(mixed $data): void
	{
		$this->data = $data;
	}

	#[\Override]
    public function &getData($paramname)
	{
		return $this->data;
	}

	#[\Override]
    public function validate()
	{
		return parent::validate();
	}

}

class EmailValidatorTest extends UnitTestCase
{
	protected ValidationManager $_vm;
	protected EmailValidatorWrapper $validator;

	#[\Override]
    public function setUp(): void
	{
		$this->_vm = $this->getContext()->createInstanceFor('validation_manager');
		$this->validator = $this->_vm->createValidator(EmailValidatorWrapper::class, []);
	}

	public function testgetContext(): void
	{
		$this->assertSame($this->getContext(), $this->validator->getContext());
	}

	public function testexecute(): void
	{
		$good = [
			'bob@quiote.org',
			'me.bob@quiote.org',
			'stupidmonkey@example.com',
			'anotherbunk@bunk-domain.com',
			'somethingelse@ez-bunk-domain.biz'
		];
		$bad = [
			'bad mojo@quiote.org',
			'bunk(data)@quiote.org',
			'bunk@quiote info.com',
			'sjklsdfsfd'
		];
		foreach ($good as &$value) {
			$this->validator->setData($value);
			$this->assertTrue($this->validator->validate(), "False negative: $value");
		}
		foreach ($bad as &$value) {
			$this->validator->setData($value);
			$this->assertFalse($this->validator->validate(), "False positive: $value");
		}
	}
}

?>
