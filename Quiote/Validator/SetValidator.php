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
	 * Returns the base Validator parameters plus 'value'.
	 *
	 * 'value' is the value this validator exports; it is passed through
	 * untouched and the validator always succeeds, so there is nothing else to
	 * configure.
	 * @return     array<int, string> The accepted parameter names.
	 */
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), ['value']);
	}

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