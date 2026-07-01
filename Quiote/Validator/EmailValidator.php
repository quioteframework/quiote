<?php
namespace Quiote\Validator;

/**
 * EmailValidator verifies if a parameter contains a value that qualifies
 * as an email address.
 * @since      1.0.0
 * @version    1.0.0
 */
class EmailValidator extends Validator
{
	/**
	 * Validates the input.
	 * @return     bool The input is a valid email address.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$data = $this->getData($this->getArgument());
		if(!is_scalar($data)) {
			// non scalar values would cause notices
			$this->throwError();
			return false;
		}
		
		return filter_var($data, FILTER_VALIDATE_EMAIL) !== false;
		
	}
}

?>