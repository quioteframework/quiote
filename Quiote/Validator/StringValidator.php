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
 * On success the (string-cast, optionally trimmed) value is written back into
 * the request under the 'export' parameter's name, or under the validator's
 * own argument name when 'export' is not configured -- so a caller reading
 * the argument back afterwards always gets a native string, never the raw
 * submitted scalar.
 * @since      1.0.0
 * @version    1.0.0
 */
class StringValidator extends Validator
{
	/**
	 * Returns the base Validator parameters plus 'min', 'max', 'trim' and
	 * 'utf8'.
	 *
	 * 'min' and 'max' bound the length in bytes and are each only checked when
	 * present. 'trim' strips surrounding whitespace before the length checks and
	 * before the value is exported; it defaults to false. 'utf8', on by default,
	 * makes that trim use the Unicode separator and control classes rather than
	 * the ASCII whitespace class.
	 * @return     array<int, string> The accepted parameter names.
	 */
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), ['min', 'max', 'trim', 'utf8']);
	}

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
		$originalValue = (string) $originalValue;

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

		$this->exportOwnArgumentByDefault($originalValue);

		return true;
	}
}

?>