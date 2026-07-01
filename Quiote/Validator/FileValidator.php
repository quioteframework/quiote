<?php
namespace Quiote\Validator;

/**
 * FileValidator verifies the size and extension of a file
 * @see        BaseFileValidator
 * @since      1.0.0
 * @version    1.0.0
 */
class FileValidator extends BaseFileValidator
{
	/**
	 * Validates the input
	 * @return     bool The file is valid according to given parameters.
	 * @since      1.0.0
	 */
    #[\Override]
    protected function validate()
	{
		return parent::validate();
	}
}

?>