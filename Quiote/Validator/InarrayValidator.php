<?php
namespace Quiote\Validator;

/**
 * InArrayValidator verifies whether an input is one of a set of values
 * Parameters:
 *   'values'  list of values that form the array
 *   'sep'     separator of values in the list
 *   'case'    verifies case sensitive if true
 *   'strict'  whether or not to do strict type comparisons with in_array()
 * @since      1.0.0
 * @version    1.0.0
 */
class InarrayValidator extends Validator
{
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), ['values', 'sep', 'case', 'strict']);
	}

	/**
	 * Validates the input.
	 * @return     bool The value is in the array.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		$list = $this->getParameter('values');
		if(!is_array($list)) {
			$list = explode($this->getParameter('sep'), (string) $list);
		}
		$value = $this->getData($this->getArgument());
		
		if(!is_scalar($value)) {
			$this->throwError();
			return false;
		}
		
		if(!$this->getParameter('case')) {
			$value = strtolower((string) $value);
			$list = array_map(static fn($item) => strtolower((string) $item), $list);
		}
		
		if(!in_array($value, $list, $this->getParameter('strict', false))) {
			$this->throwError();
			return false;
		}
		
		return true;
	}
}

?>