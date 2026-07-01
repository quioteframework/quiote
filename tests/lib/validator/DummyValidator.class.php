<?php

use Quiote\Validator\Validator;

class DummyValidator extends Validator
{
	public $cleared = false;
	public $val_result = true;
	public $validated = false;
	public $shutdown = false;
	public $throw_on_execute = false;
	
	protected function validate()
	{
		if($this->throw_on_execute) {
			throw new \RuntimeException('validator boom');
		}
		$this->validated = true;
		if($this->val_result == false) {
			$this->throwError();
		}
		return $this->val_result;
	}
	public function clear() { $this->cleared = true; $this->validated = false; $this->shutdown = false;}
	public function shutdown() { $this->shutdown = true; }
	
	public function getErrorMessages() {
		return $this->errorMessages;
	}
}
?>