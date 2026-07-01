<?php
namespace Quiote\Validator;

/**
 * IsNotEmptyValidator verifies a parameter is not empty
 * The content of the input value is not verified in any manner, it is only
 * checked if the input value exists and is not empty. It lets the data holder
 * implementation decide what is regarded as empty.
 * @since      1.0.0
 * @version    1.0.0
 */
class IsNotEmptyValidator extends Validator
{
	/**
	 * Validates the input.
	 * @return     bool The value is set.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		// we don't need to do any checking here because validate will only be
		// called when all values it needs were non empty.
		return true;
	}
}

?>