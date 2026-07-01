<?php
namespace Quiote\Validator;

/**
 * SetValidator only exports a value and always succeeds
 * Parameters:
 *   'value'  value that should be exported
 * @since      1.0.0
 * @version    1.0.0
 */
class SetValidator extends Validator
{
	/**
	 * Exports the value and returns true.
	 * @return     bool Always returns true.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$this->export($this->getParameter('value'));
		
		return true;
	}
}

?>