<?php
namespace Quiote\Validator;

/**
 * JsonValidator verifies if a parameter contains a value that is valid
 * JSON. On success the decoded value is written back into the request under
 * the 'export' parameter's name, or under the validator's own argument name
 * when 'export' is not configured -- so a caller reading the argument back
 * afterwards gets the decoded array/object/scalar, never the raw JSON string.
 * @since      1.0.0
 * @version    1.0.0
 */
class JsonValidator extends Validator
{
	/**
	 * Returns the base Validator parameters plus 'assoc'.
	 *
	 * 'assoc' is the boolean handed to json_decode(): true, the default, decodes
	 * objects into associative arrays, false into stdClass instances. It only
	 * affects the shape of the exported decoded value, not whether the input
	 * validates. A non-boolean value raises a parameter-type error at validation
	 * time.
	 * @return     array<int, string> The accepted parameter names.
	 */
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), ['assoc']);
	}

	/**
	 * @var array<int, string>
	 */
	protected $jsonErrors = [
		'depth',
		'state_mismatch',
		'ctrl_char',
		'syntax',
		'utf8',
		'recursion',
		'inf_or_nan',
		'unsupported_type',
	];
	
	/**
	 * Validates the input.
	 * @return     bool The input is valid JSON.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$json = $this->getData($this->getArgument());

		if(!is_scalar($json)) {
			// non scalar values would cause notices
			$this->throwError();
			return false;
		}
		$json = (string) $json;

		$assoc = $this->getParameter('assoc', true);
		if(!is_bool($assoc)) {
			throw $this->invalidParameterType('assoc', 'a boolean', $assoc);
		}

		$ret = json_decode($json, $assoc);

		if($json !== '' && $ret === null) {
			$jsonError = json_last_error();
			foreach($this->jsonErrors as $errorName) {
				$constName = 'JSON_ERROR_' . strtoupper((string) $errorName);
				if(defined($constName) && constant($constName) === $jsonError) {
					$this->throwError($errorName);
					return false;
				}
			}
			
			$this->throwError();
			return false;
		} else {
			$this->exportOwnArgumentByDefault($ret);
			return true;
		}
	}
}

?>