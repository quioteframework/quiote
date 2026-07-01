<?php
namespace Quiote\Validator;

/**
 * StringValidator allows you to apply string-related constraints to a
 * parameter.
 * Parameters:
 *   'min'  string should be at least this long
 *   'max'  string should be at most this long
 *   'trim' trim whitespace before length checks
 *   'utf8' whether or not to treat input as UTF-8 (defaults to true)
 * @since      1.0.0
 * @version    1.0.0
 */
class StringValidator extends Validator
{
	/**
	 * Validates the input.
	 * @return     bool True if the string is valid according to the given 
	 *                  parameters
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$utf8 = $this->getParameter('utf8', true);
		
		$originalValue = $this->getData($this->getArgument());
		
		if(!is_scalar($originalValue)) {
			// non scalar values would cause notices
			$this->throwError();
			return false;
		}
		
		if($this->getParameter('trim', false)) {
			if($utf8) {
				$pattern = '/^[\pZ\pC]*+(?P<trimmed>.*?)[\pZ\pC]*+$/usDS';
			} else {
				$pattern = '/^\s*+(?P<trimmed>.*?)\s*+$/sDS';
			}
			if(preg_match($pattern, $originalValue, $matches)) {
				$originalValue = $matches['trimmed'];
			}
		}
		
		$value = $originalValue;
		
		/*if($utf8) {
			$value = utf8_decode($value);
		}*/
		
		if($this->hasParameter('min') and strlen($value) < $this->getParameter('min')) {
			$this->throwError('min');
			return false;
		}
		
		if($this->hasParameter('max') and strlen($value) > $this->getParameter('max')) {
			$this->throwError('max');
			return false;
		}

		$this->export($originalValue);

		return true;
	}
}

?>