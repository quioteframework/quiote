<?php

namespace Sandbox\Testing;

use Quiote\Validator\Validator;

class DummyValidator extends Validator
{
	public bool $cleared = false;
	public bool $val_result = true;
	public bool $validated = false;
	public bool $shutdown = false;

	protected function validate(): bool
	{
		$this->validated = true;
		if($this->val_result == false) {
			$this->throwError();
		}
		return $this->val_result;
	}
	public function clear(): void { $this->cleared = true; $this->validated = false; $this->shutdown = false;}
	public function shutdown(): void { $this->shutdown = true; }
	public function checkValidSetup(): bool
	{
		return true;
	}
}
