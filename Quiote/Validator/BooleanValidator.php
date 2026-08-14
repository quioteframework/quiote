<?php
namespace Quiote\Validator;

use Quiote\Util\Toolkit;

/**
 * BooleanValidator verifies a parameter is a valid boolean
 * Accepted values are string 0/1, int 0/1, bool true/false, string yes/no,
 * string true/false, string on/off - basically all values that
 * {@see Toolkit::literalize()} will accept.
 * On success the value is cast to a native bool and written back into the
 * request under the 'export' parameter's name, or under the validator's own
 * argument name when 'export' is not configured.
 * @since      1.0.0
 * @version    1.0.0
 */
class BooleanValidator extends Validator
{
	/**
	 * Validates the input.
	 * @return     bool The value is a valid boolean
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$value = $this->getData($this->getArgument());
		$castValue = $value;
		
		if(is_bool($castValue)) {
			// noop
		} elseif(1 === $castValue || '1' === $castValue) {
			$castValue = true;
		} elseif(0 === $castValue || '0' === $castValue) {
			$castValue = false;
		} elseif(is_string($castValue)) {
			$castValue = Toolkit::literalize($castValue);
		}
		
		if(is_bool($castValue)) {
			$this->exportOwnArgumentByDefault($castValue);
			return true;
		}
		
		$this->throwError('type');
		
		return false;
	}
}

?>