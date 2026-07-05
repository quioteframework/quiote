<?php
namespace Quiote\Validator;

use Symfony\Contracts\Service\ResetInterface;

/**
 * ValidationIncident is erroneous result of an validation run.
 * @since      1.0.0
 * @version    1.0.0
 */
class ValidationIncident implements ResetInterface
{
	/**
	 * @var        array<int, ValidationError> The errors of this incident.
	 */
	protected $errors = [];

	/**
     * Constructor
     * @param      Validator $validator The validator which caused this incident (null
     *                            for errors thrown not in the validation)
     * @param      int $severity The severity of the incident
     * @since      1.0.0
     */
    public function __construct(
        /**
         * @var        ?Validator The source of this incident.
         */
        protected $validator,
        /**
         * @var        int The severity of this incident.
         */
        protected $severity = Validator::ERROR
    )
    {
    }

	/**
	 * Sets the severity of this incident.
	 * @param      int $severity The severity.
	 * @return     int
	 * @since      1.0.0
	 */
	public function setSeverity($severity)
	{
		return $this->severity = $severity;
	}

	/**
	 * Retrieves the severity of this incident.
	 * @return     int The severity.
	 * @since      1.0.0
	 */
	public function getSeverity()
	{
		return $this->severity;
	}

	/**
	 * Adds an error to this incident. This will set the incident of the error to 
	 * this incident instance.
	 * @param      ValidationError $error The error.
	 * @return     void
	 * @since      1.0.0
	 */
	public function addError(ValidationError $error)
	{
		$error->setIncident($this);
		$this->errors[] = $error;
	}

	/**
	 * Sets the errors of this incident.
	 * @param      array<int, ValidationError> $errors An array of ValidationErrors.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setErrors(array $errors)
	{
		foreach($errors as $error) {
			$error->setIncident($this);
		}
		$this->errors = $errors;
	}

	/**
	 * Retrieves the errors of this incident.
	 * @return     array<int, ValidationError> The errors.
	 * @since      1.0.0
	 */
	public function getErrors()
	{
		return $this->errors;
	}

	/**
	 * Sets the validator of this incident.
	 * @param      ?Validator $validator The validator.
	 * @return     ?Validator
	 * @since      1.0.0
	 */
	public function setValidator($validator)
	{
		return $this->validator = $validator;
	}

	/**
	 * Retrieves the validator of this incident.
	 * @return     ?Validator The validator.
	 * @since      1.0.0
	 */
	public function getValidator()
	{
		return $this->validator;
	}

	/**
	 * Retrieves a list of all erroneous arguments of this incident.
	 * @return     array<string, ValidationArgument> An array of ValidationArgument.
	 * @since      1.0.0
	 */
	public function getArguments()
	{
		$arguments = [];
		foreach($this->errors as $error) {
			foreach($error->getArguments() as $argument) {
				$arguments[$argument->getHash()] = $argument;
			}
		}

		return $arguments;
	}
	
	
	/////////////////////////////////////////////////////////////////////////////
    ////////////////////////////// Deprecated Parts /////////////////////////////
    /////////////////////////////////////////////////////////////////////////////
    /**
     * Checks if any of the errors of this incident were thrown for the given
     * field name.
     * @param      string $fieldname The field name.
     * @return     bool The result.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function hasFieldError($fieldname)
	{
		$argument = new ValidationArgument($fieldname);
		foreach($this->errors as $error) {
			if($error->hasArgument($argument)) {
				return true;
			}
		}

		return false;
	}

	/**
     * Retrieves a list of all fields of all the containing errors.
     * @return     array<int, string> An array of field names.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getFields()
	{
		$fields = [];
		foreach($this->errors as $error) {
			$fields = array_merge($fields, $error->getFields());
		}

		return array_unique($fields);
	}

	/**
     * Retrieves the errors which were thrown for the given field.
     * @param      string $fieldname The field name.
     * @return     array<int, ValidationError> An array of ValidationError.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getFieldErrors($fieldname)
	{
		$argument = new ValidationArgument($fieldname);
		$errors = [];
		foreach($this->errors as $error) {
			if($error->hasArgument($argument)) {
				$errors[] = $error;
			}
		}

		return $errors;
	}

	public function reset() : void
	{
		$this->validator = null;
		$this->severity = Validator::ERROR;
		$this->errors = [];
	}

}

?>