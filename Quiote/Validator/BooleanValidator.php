<?php
namespace Quiote\Validator;

use Quiote\Exception\ValidatorException;
use Quiote\Util\Toolkit;

/**
 * BooleanValidator verifies a parameter is a valid boolean
 * Accepted values are string 0/1, int 0/1, bool true/false, string yes/no,
 * string true/false, string on/off - basically all values that 
 * {@see Toolkit::literalize()} will accept.
 * The value will be casted to the respective boolean unless it's exported. If
 * the export parameter is given, the value will be retained in its original
 * form.
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
			if($this->hasParameter('export')) {
				$this->export($castValue);
			} else {
				// Persist casted value back into request runtime parameters so subsequent validators/actions see normalized boolean.
				try {
					$validationParameters = $this->validationParameters;
					if($validationParameters === null) {
						throw new ValidatorException('Validator "' . ($this->getName() ?? '?') . '" has no request; validate() ran before execute() supplied one.');
					}
					$argumentName = $this->getArgument();
					if($argumentName !== null) {
						$this->validationParameters = $validationParameters->setParameter($argumentName, $castValue);
					}
				} catch(\Throwable) {}
			}
			return true;
		}
		
		$this->throwError('type');
		
		return false;
	}
}

?>