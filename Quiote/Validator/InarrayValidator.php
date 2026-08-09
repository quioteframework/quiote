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
	/**
	 * Returns the base Validator parameters plus 'values', 'sep', 'case' and
	 * 'strict'.
	 *
	 * 'values' holds the allowed set, either as an array of scalars or as a
	 * single string that is split on 'sep' (which must then be a non-empty
	 * string). 'case' makes the comparison case-sensitive; when it is falsy both
	 * the input and the allowed values are lowercased first. 'strict' is the
	 * third argument to in_array(), so it turns the membership test into a
	 * type-strict one; it defaults to false.
	 * @return     array<int, string> The accepted parameter names.
	 */
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
			if(!is_string($list)) {
				throw $this->invalidParameterType('values', 'an array or a string', $list);
			}
			$sep = $this->getParameter('sep');
			if(!is_string($sep) || $sep === '') {
				throw $this->invalidParameterType('sep', 'a non-empty string', $sep);
			}
			$list = explode($sep, $list);
		}

		$scalarList = [];
		foreach($list as $item) {
			if(!is_scalar($item)) {
				throw $this->invalidParameterType('values', 'a list of scalar values', $item);
			}
			$scalarList[] = $item;
		}

		$value = $this->getData($this->getArgument());

		if(!is_scalar($value)) {
			$this->throwError();
			return false;
		}

		if(!$this->getParameter('case')) {
			$value = strtolower((string) $value);
			$scalarList = array_map(static fn($item) => strtolower((string) $item), $scalarList);
		}

		$strict = $this->getParameter('strict', false);
		if(!is_bool($strict)) {
			throw $this->invalidParameterType('strict', 'a boolean', $strict);
		}

		if(!in_array($value, $scalarList, $strict)) {
			$this->throwError();
			return false;
		}

		return true;
	}
}

?>