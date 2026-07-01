<?php
namespace Quiote\Validator;

/**
 * RegexValidator allows you to match a value against a regular expression
 * pattern.
 * Parameters:
 *   'pattern'  PCRE to be used in preg_match
 *   'match'    input should match or not
 *   'export'   string with name of argument to export entire value to, or an
 *              array of subpatterns names as keys and argument names as values
 *              to selectively export one or more parts of the value
 * @since      1.0.0
 * @version    1.0.0
 */
class RegexValidator extends Validator
{
	/**
	 * Validates the input.
	 * @return     bool True if input matches the pattern in 'match'.
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
		
		$result = preg_match($this->getParameter('pattern'), $data, $matches);
		
		if($result != $this->getParameter('match')) {
			$this->throwError();
			return false;
		}
		
		if($this->hasParameter('export')) {
			$export = $this->getParameter('export');
			// if the result was positive (makes no sense for negative matches) and "export" is an array...
			if($result && is_array($export)) {
				// ...treat it as a map of subpattern names and argument names for exporting parts of the value
				foreach($export as $subpattern => $argument) {
					if(isset($matches[$subpattern])) {
						$this->export($matches[$subpattern], $argument);
					}
				}
			} else {
				// otherwise, just export the whole input
				$this->export($data);
			}
		}
		
		return true;
	}
}

?>