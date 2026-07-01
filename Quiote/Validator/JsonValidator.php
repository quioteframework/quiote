<?php
namespace Quiote\Validator;

/**
 * JsonValidator verifies if a parameter contains a value that is valid
 * JSON and optionally exports the decoded value.
 * @since      1.0.0
 * @version    1.0.0
 */
class JsonValidator extends Validator
{
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
		
		$ret = json_decode((string) $json, $this->getParameter('assoc', true));
		
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
			$this->export($ret);
			return true;
		}
	}
}

?>