<?php
namespace Quiote\Validator;

use Symfony\Contracts\Service\ResetInterface;

/**
 * ValidationReport stores the result of a validation run.
 * @since      1.0.0
 * @version    1.0.0
 */
class ValidationReport implements IValidationReportQuery, ResetInterface
{
	/**
	 * @var        array A List of result severities for each argument which has been validated.
	 */
	protected $argumentResults = [];
	
	/**
	 * @var        ?int The highest error severity thrown by the validation run.
	 */
	protected $result = null;
	
	/**
	 * @var        array The incidents which were thrown by the validation run.
	 */
	protected $incidents = [];
	
	/**
	 * @var        array The depend tokens provided by the validation run.
	 */
	protected $providedDependTokens = [];
	
	/**
	 * Retrieves the highest validation result code in this report.
	 * @return     ?int An Validator::* severity constant, or null if there is
	 *                 no result. Please remember to do a strict === comparison if
	 *                 you are comparing against Validator::SUCCESS.
	 * @since      1.0.0
	 */
	public function getResult()
	{
		return $this->result;
	}
	
	/**
	 * Sets the validation result
	 * @param      int $result The new validation result
	 * @since      1.0.0
	 */
	public function setResult($result)
	{
		$this->result = $result;
	}
	
	/**
	 * Adds an incident to the validation result. This will automatically adjust
	 * the argument result table (which is required because one can still 
	 * manually add errors either via addError or by directly using this method)
	 * @param      ValidationIncident $incident The incident.
	 * @since      1.0.0
	 */
	public function addIncident(ValidationIncident $incident)
	{
		// we need to add the fields to our fieldresults if they don't exist there 
		// yet and adjust our result if needed (which only happens when this method
		// is called not from a validator)
		$severity = $incident->getSeverity();
		$validator = $incident->getValidator();
		if($severity > $this->result || null === $this->result) {
			$this->result = $severity;
		}
		// store the result for the argument if it's not stored yet
		foreach($incident->getArguments() as $argument) {
			$this->addArgumentResult($argument, $severity, $validator);
		}
		// ValidationIncident::getValidator() can genuinely return null (incidents
		// raised outside a validator, e.g. ValidationManager::setError()/setErrors()),
		// even though its return type is documented as non-nullable elsewhere.
		// Guard against that here without relying on a null check on $validator
		// itself.
		try {
			$name = $validator->getName();
		} catch (\Throwable) {
			$name = '';
		}
		$this->incidents[$name][] = $incident;
	}
	
	/**
	 * Checks if any incidents occurred Returns all arguments which succeeded 
	 * in the validation. Includes arguments which were not processed (happens
	 *  when the argument is "not set" and the validator is not required)
	 * @return     bool The result.
	 * @since      1.0.0
	 */
	public function hasIncidents()
	{
		return count($this->incidents) > 0;
	}
	
	/**
	 * Returns all incidents which happened during the execution of the 
	 * validation.
	 * @return     array The incidents.
	 * @since      1.0.0
	 */
	public function getIncidents()
	{
		$incidents = [];
		foreach($this->incidents as $validatorIncidents) {
			$incidents = array_merge($incidents, $validatorIncidents);
		}
		return $incidents;
	}
	
	/**
	 * Sets dependency tokens provided by executed validators onto the result.
	 * @param      array $dependTokens The depend tokens of the DependencyManager.
	 * @since      1.0.0
	 */
	public function setDependTokens(array $dependTokens = [])
	{
		$this->providedDependTokens = $dependTokens;
	}
	
	/**
	 * Check whether the given depend token was provided by the validation run.
	 * @param      string $token Name of depend token suspected to have been provided.
	 * @return     bool True if depend token was provided.
	 * @since      1.0.0
	 */
	public function hasDependToken($token)
	{
		return array_key_exists($token, $this->getDependTokens());
	}
	
	/**
	 * Check whether the given depend token was provided by the validation run.
	 * @return     array All provided depend tokens.
	 * @since      1.0.0
	 */
	public function getDependTokens()
	{
		return $this->providedDependTokens;
	}
	
	/**
	 * Adds a intermediate result of an validator for the given argument
	 * @param      ValidationArgument $argument The argument
	 * @param      int $result The arguments result.
	 * @param      Validator $validator The validator (if the error was cause inside 
	 *                            a validator).
	 * @since      1.0.0
	 */
	public function addArgumentResult(ValidationArgument $argument, $result, ?Validator $validator = null)
	{
		$this->argumentResults[$argument->getHash()][] = [
			'argument' => $argument,
			'severity' => $result,
			'validator' => $validator,
		];
	}
	
	/**
	 * Retrieve the internal array (indexed by argument hash) of
	 * argument/severity/validator tuples.
	 * This method exposes an internal data structure that may change at any time.
	 * You shouldn't have to use this method.
	 * Don't even think about using it to harm cute little animals, or you shall
	 * suffer the wrath of an angry god.
	 * @return     array An array of argument result info arrays.
	 * @since      1.0.0
	 */
	public function getArgumentResults()
	{
		return $this->argumentResults;
	}
	
	/**
	 * Will return the highest error severity for an argument. If the field was
	 * not "touched" by a validator null is returned. Can optionally be restricted
	 * to the severity of just one specific validator.
	 * @param      ValidationArgument $argument The argument.
	 * @param      string $validatorName Optional name of a specific validator
	 *                                     to get a result for.
	 * @return     ?int The error severity.
	 * @since      1.0.0
	 */
	public function getAuthoritativeArgumentSeverity(ValidationArgument $argument, $validatorName = null)
	{
		if(!isset($this->argumentResults[$argument->getHash()])) {
			return null;
		}

		$severity = null;
		
		foreach($this->argumentResults[$argument->getHash()] as $result) {
			if($validatorName === null || ($result['validator'] instanceof Validator && $result['validator']->getName() == $validatorName)) {
				if(null === $severity) {
					$severity = Validator::NOT_PROCESSED;
				}
				$severity = max($severity, $result['severity']);
			}
		}

		return $severity;
	}
	
	/**
	 * Checks whether an argument has been processed by a validator (this 
	 * includes arguments which were skipped because their value was not set 
	 * and the validator was not required)
	 * @param      ValidationArgument $argument The argument.
	 * @return     bool Whether the argument was validated.
	 * @since      1.0.0
	 */
	public function isArgumentValidated(ValidationArgument $argument)
	{
		return isset($this->argumentResults[$argument->getHash()]);
	}
	
	/**
	 * Checks whether an argument has failed in any validator.
	 * @param      ValidationArgument $argument The argument.
	 * @return     bool Whether the validating that argument has failed.
	 * @since      1.0.0
	 */
	public function isArgumentFailed(ValidationArgument $argument)
	{
		$severity = $this->getAuthoritativeArgumentSeverity($argument);
		return ($severity > Validator::SUCCESS);
	}
	
	/**
	 * Returns all arguments which validated successfully.
	 * @param      string $source Optional source name to limit the list of arguments to.
	 * @return     array An array of ValidationArgument objects.
	 * @since      1.0.0
	 */
	public function getSucceededArguments($source = null)
	{
		$arguments = [];
		foreach($this->argumentResults as $results) {
			$hasInSource = false;
			$severity = Validator::NOT_PROCESSED;
			foreach($results as $result) {
				if($source === null || $result['argument']->getSource() == $source) {
					$hasInSource = true;
					$severity = max($severity, $result['severity']);
				}
			}
			if($hasInSource && $severity >= Validator::SUCCESS && $severity <= Validator::INFO) {
				$argument = $results[0]['argument'];
				$arguments[$argument->getHash()] = $argument;
			}
		}

		return $arguments;
	}
	
	/**
	 * Returns all arguments which failed in the validation.
	 * @param      string $source Optional source name to limit the list of arguments to.
	 * @return     array An array of ValidationArgument objects.
	 * @since      1.0.0
	 */
	public function getFailedArguments($source = null)
	{
		// shortcut if validation was successful - there won't be failed args in that case
		if($this->getResult() <= Validator::INFO) {
			return [];
		}
		
		$arguments = [];
		foreach($this->argumentResults as $results) {
			$hasInSource = false;
			$severity = Validator::NOT_PROCESSED;
			foreach($results as $result) {
				if($source === null || $result['argument']->getSource() == $source) {
					$hasInSource = true;
					$severity = max($severity, $result['severity']);
				}
			}
			if($hasInSource && $severity > Validator::INFO) {
				$argument = $results[0]['argument'];
				$arguments[$argument->getHash()] = $argument;
			}
		}

		return $arguments;
	}
	
	/**
	 * Create a new ValidationReportQuery for this report.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function createQuery()
	{
		return new ValidationReportQuery($this);
	}
	
	/**
	 * Returns a new IValidationReportQuery which returns only the incidents
	 * for the given argument (and the other existing filter rules).
	 * @param      ValidationArgument|string|array $argument The argument instance, or
	 *                                                  a parameter name, or an
	 *                                                  array of these elements.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byArgument($argument)
	{
		return $this->createQuery()->byArgument($argument);
	}
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * for the given validator (and the other existing filter rules).
	 * @param      string|array $name The name of the validator, or an array of names.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byValidator($name)
	{
		return $this->createQuery()->byValidator($name);
	}
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * for the given error name (and the other existing filter rules).
	 * @param      string|array $name The name of the error, or an array of names.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byErrorName($name)
	{
		return $this->createQuery()->byErrorName($name);
	}
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * of the given severity or higher (and the other existing filter rules).
	 * @param      int $minSeverity The minimum severity.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byMinSeverity($minSeverity)
	{
		return $this->createQuery()->byMinSeverity($minSeverity);
	}
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * of the given severity or lower (and the other existing filter rules).
	 * @param      int $maxSeverity The maximum severity.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byMaxSeverity($maxSeverity)
	{
		return $this->createQuery()->byMaxSeverity($maxSeverity);
	}
	
	/**
	 * Retrieves all ValidationError objects in this report.
	 * @return     array An array of ValidationError objects.
	 * @since      1.0.0
	 */
	public function getErrors()
	{
		return $this->createQuery()->getErrors();
	}
	
	/**
	 * Retrieves all error messages in this report.
	 * @return     array An array of message strings.
	 * @since      1.0.0
	 */
	public function getErrorMessages()
	{
		return $this->createQuery()->getErrorMessages();
	}

	/**
	 * Retrieves all error messages together with the fields that caused them.
	 * Returns the same array('message' => string, 'errors' => array) structure as
	 * the deprecated ValidationManager::getErrorMessages(), but as a
	 * non-deprecated accessor. Callers migrating off the deprecated method can use
	 * getReport()->getErrorMessagesWithFields() to keep the field-annotated shape.
	 * @return     array An array of array('message' => string, 'errors' => array).
	 * @since      1.0.0
	 */
	public function getErrorMessagesWithFields()
	{
		return $this->createQuery()->getErrorMessagesWithFields();
	}
	
	/**
	 * Retrieves all ValidationArgument objects in this report.
	 * @return     array An array of ValidationArgument objects.
	 * @since      1.0.0
	 */
	public function getArguments()
	{
		return $this->createQuery()->getArguments();
	}
	
	/**
	 * Check if there are any incidents matching the currently defined filter
	 * rules.
	 * @return     bool Whether or not any incidents exist in this report.
	 * @since      1.0.0
	 */
	public function has()
	{
		return $this->createQuery()->has();
	}
	
	/**
	 * Get the number of incidents matching the currently defined filter rules.
	 * @return     int The number of incidents in this report.
	 * @since      1.0.0
	 */
	public function count()
	{
		return $this->createQuery()->count();
	}

	public function reset(): void {
		$this->argumentResults = [];
		$this->result = null;
		$this->incidents = [];
		$this->providedDependTokens = [];
	}
}

?>
