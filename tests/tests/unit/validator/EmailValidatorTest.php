<?php

use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\EmailValidator;

class EmailValidatorWrapper extends EmailValidator
{
	protected $data;


	public function setData($data)
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
	protected $_vm, $validator;
	
	#[\Override]
    public function setUp(): void
	{
		$this->_vm = $this->getContext()->createInstanceFor('validation_manager');
		$this->validator = $this->_vm->createValidator('EmailValidatorWrapper', []);
	}

	#[\Override]
    public function tearDown(): void
	{
		unset($this->validator);
	}

	public function testgetContext()
	{
		$this->assertTrue($this->validator->getContext() instanceof Context);
	}
	
	public function testexecute()
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
		$error = '';
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