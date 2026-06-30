<?php

use Agavi\AgaviContext;
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Validator\AgaviEmailValidator;

class EmailValidatorWrapper extends AgaviEmailValidator
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

class AgaviEmailValidatorTest extends AgaviUnitTestCase
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
		$this->assertTrue($this->validator->getContext() instanceof AgaviContext);
	}
	
	public function testexecute()
	{
		$good = [
			'bob@agavi.org',
			'me.bob@agavi.org',
			'stupidmonkey@example.com',
			'anotherbunk@bunk-domain.com',
			'somethingelse@ez-bunk-domain.biz'
		];
		$bad = [
			'bad mojo@agavi.org',
			'bunk(data)@agavi.org',
			'bunk@agavi info.com',
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