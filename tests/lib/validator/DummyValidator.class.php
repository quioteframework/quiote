<?php

use Quiote\Validator\Validator;

class DummyValidator extends Validator
{
	public bool $cleared = false;
	public bool $val_result = true;
	public bool $validated = false;
	public bool $shutdown = false;
	public bool $throw_on_execute = false;

	protected function validate(): bool
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
	public function clear(): void { $this->cleared = true; $this->validated = false; $this->shutdown = false;}
	public function shutdown(): void { $this->shutdown = true; }

	/** @return array<string, string> */
	public function getErrorMessages(): array {
		return $this->errorMessages;
	}
}
?>