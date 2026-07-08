<?php
namespace Quiote\Validator;

/**
 * ValidationReportQuery allows queries against the validation run report.
 * @since      1.0.0
 * @version    1.0.0
 */
class ValidationReportQuery implements IValidationReportQuery
{
	/**
	 * @var        ValidationReport
	 */
	protected $report;
	
	/**
	 * @var        ?array<int, ValidationArgument>
	 */
	protected $argumentFilter;

	/**
	 * @var        ?array<int, string>
	 */
	protected $errorNameFilter;

	/**
	 * @var        ?array<int, string>
	 */
	protected $validatorFilter;
	
	/**
	 * @var        array<int, mixed>|int|null
	 */
	protected $minSeverityFilter;

	/**
	 * @var        array<int, mixed>|int|null
	 */
	protected $maxSeverityFilter;
	
	/**
	 * Constructor.
	 * @param      ValidationReport $report The validation report instance.
	 * @since      1.0.0
	 */
	public function __construct(ValidationReport $report)
	{
		$this->report = $report;
	}
	
	/**
	 * Returns a new IValidationReportQuery which returns only the incidents
	 * for the given argument (and the other existing filter rules).
	 * @param      ValidationArgument|string|array<int, ValidationArgument|string> $argument The argument instance, or
	 *                                                  a parameter name, or an
	 *                                                  array of these elements.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byArgument($argument)
	{
		if(is_array($argument)) {
			$normalized = [];
			foreach($argument as $arg) {
				$normalized[] = $arg instanceof ValidationArgument ? $arg : new ValidationArgument($arg);
			}
		} else {
			$normalized = [$argument instanceof ValidationArgument ? $argument : new ValidationArgument($argument)];
		}
		$obj = clone $this;
		$obj->argumentFilter = $normalized;
		return $obj;
	}
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * for the given validator (and the other existing filter rules).
	 * @param      string|array<int, string> $name The name of the validator, or an array of names.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byValidator($name)
	{
		if(!is_array($name)) {
			$name = [$name];
		}
		$obj = clone $this;
		$obj->validatorFilter = $name;
		return $obj;
	}
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * for the given error name (and the other existing filter rules).
	 * @param      string|array<int, string> $name The name of the error, or an array of names.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byErrorName($name)
	{
		if(!is_array($name)) {
			$name = [$name];
		}
		$obj = clone $this;
		$obj->errorNameFilter = $name;
		return $obj;
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
		$obj = clone $this;
		$obj->minSeverityFilter = $minSeverity;
		return $obj;
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
		$obj = clone $this;
		$obj->maxSeverityFilter = $maxSeverity;
		return $obj;
	}
	
	/**
	 * Retrieves the incidents filtered with the current filter rules.
	 * @return     array<int, ValidationIncident>
	 * @since      1.0.0
	 */
	protected function getFilteredIncidents()
	{
		$incidents = $this->report->getIncidents();
		$resultIncidents = [];
		foreach($incidents as $incident) {
			if($this->validatorFilter) {
				$validator = $incident->getValidator();
				if(!$validator || !in_array($validator->getName(), $this->validatorFilter)) {
					continue;
				}
			}
			if($this->argumentFilter) {
				$hasArgument = false;
				foreach($incident->getArguments() as $argument) {
					if(in_array($argument, $this->argumentFilter)) {
						$hasArgument = true;
						break;
					}
				}
				if(!$hasArgument) {
					continue;
				}
			}
			
			if($this->errorNameFilter) {
				$hasErrorName = false;
				foreach($incident->getErrors() as $error) {
					if(in_array($error->getName(), $this->errorNameFilter)) {
						$hasErrorName = true;
						break;
					}
				}
				if(!$hasErrorName) {
					continue;
				}
			}
			
			if($this->minSeverityFilter) {
				if($incident->getSeverity() < $this->minSeverityFilter) {
					continue;
				}
			}
			
			if($this->maxSeverityFilter) {
				if($incident->getSeverity() > $this->maxSeverityFilter) {
					continue;
				}
			}
			
			$resultIncidents[] = $incident;
		}
		return $resultIncidents;
	}
	
	/**
	 * Retrieves all incidents which match the currently defined filter rules.
	 * @return     array<int, ValidationIncident> An array of ValidationIncident objects.
	 * @since      1.0.0
	 */
	public function getIncidents()
	{
		return $this->getFilteredIncidents();
	}
	
	/**
	 * Retrieves all ValidationError objects which match the currently
	 * defined filter rules.
	 * @return     array<int, ValidationError> An array of ValidationError objects.
	 * @since      1.0.0
	 */
	public function getErrors()
	{
		$incidents = $this->getFilteredIncidents();
		$errors = [];
		foreach($incidents as $incident) {
			foreach($incident->getErrors() as $error) {
				if(!$this->errorNameFilter || in_array($error->getName(), $this->errorNameFilter)) {
					$errors[] = $error;
				}
			}
		}
		
		return $errors;
	}
	
	/**
	 * Retrieves all error messages which match the currently defined filter
	 * rules.
	 * @return     array<int, string> An array of message strings.
	 * @since      1.0.0
	 */
	public function getErrorMessages()
	{
		$errors = $this->getErrors();
		$errorMessages = [];
		foreach($errors as $error) {
			$errorMessages[] = $error->getMessage();
		}
		return $errorMessages;
	}

	/**
	 * Retrieves all error messages together with the fields that caused them,
	 * matching the currently defined filter rules.
	 * Returns the same array('message' => string, 'errors' => array) structure as
	 * the deprecated ValidationManager::getErrorMessages(), but as a
	 * non-deprecated report-query accessor.
	 * @return     array<int, array{message: string, errors: array<int, string>}> An array of array('message' => string, 'errors' => array).
	 * @since      1.0.0
	 */
	public function getErrorMessagesWithFields()
	{
		$result = [];
		foreach($this->getErrors() as $error) {
			$result[] = ['message' => $error->getMessage(), 'errors' => $error->getFields()];
		}
		return $result;
	}
	
	/**
	 * Retrieves all ValidationArgument objects which match the currently
	 * defined filter rules.
	 * @return     array<int, ValidationArgument> An array of ValidationArgument objects.
	 * @since      1.0.0
	 */
	public function getArguments()
	{
		$errors = $this->getErrors();
		$arguments = [];
		foreach($errors as $error) {
			foreach($error->getArguments() as $argument) {
				if(!$this->argumentFilter || in_array($argument, $this->argumentFilter)) {
					$arguments[$argument->getHash()] = $argument;
				}
			}
		}
		return array_values($arguments);
	}
	
	/**
	 * Check if there are any incidents matching the currently defined filter
	 * rules.
	 * @return     bool Whether or not any incidents exist for the currently
	 *                  defined filter rules.
	 * @since      1.0.0
	 */
	public function has()
	{
		return $this->count() > 0;
	}
	
	/**
	 * Get the number of incidents matching the currently defined filter rules.
	 * @return     int The number of incidents matching the currently defined
	 *                 filter rules.
	 * @since      1.0.0
	 */
	public function count()
	{
		return count($this->getIncidents());
	}
	
	/**
	 * Retrieves the highest validation result code of the collection composed of
	 * the currently defined filter rules.
	 * @return     ?int An Validator::* severity constant, or null if there is
	 *                 no result for this filter combination. Please remember to
	 *                 do a strict === comparison if you are comparing against
	 *                 Validator::SUCCESS.	 */
	public function getResult()
	{
		// if a filter for error names exist the result can't be success/not processed
		// since if you have an error name the field must have thrown an error
		$results = [];
		
		$arguments = [];
		foreach($this->getArguments() as $argument) {
			$arguments[$argument->getHash()] = $argument;
		}
		
		// lets start by looking at the incidents, if we find any, lets return the max result
		// (because since anything "below" an incident will have the same result as the incident, looking at the incidents is sufficient)
		// if there is no result in the incidents, the field was either not touched at all by validation,
		// or is stored in the argument results of the report, which we will then search instead
		foreach($this->getIncidents() as $incident) {
			$results[] = $incident->getSeverity();
		}
		
		if($results) {
			return max($results);
		} elseif($this->errorNameFilter) {
			return null;
		} else {
			$results = [];
			$argumentFilter = $this->argumentFilter;
			if($argumentFilter !== null && count($argumentFilter) == 1) {
				// retrieve the argument filter independent of the key
				$argument = reset($argumentFilter);
				if($this->validatorFilter) {
					foreach($this->validatorFilter as $validatorName) {
						$result = $this->report->getAuthoritativeArgumentSeverity($argument, $validatorName);
						if($result !== null) {
							$results[] = $result;
						}
					}
				} else {
					$result = $this->report->getAuthoritativeArgumentSeverity($argument);
					if($result !== null) {
						$results[] = $result;
					}
				}
			} else {
				foreach($this->report->getArgumentResults() as $argumentResults) {
					foreach($argumentResults as $argumentResult) {
						if(
							(!$this->argumentFilter || in_array($argumentResult['argument'], $this->argumentFilter)) &&
							(!$this->validatorFilter || ($argumentResult['validator'] && in_array($argumentResult['validator']->getName(), $this->validatorFilter)))
						) {
							$results[] = $argumentResult['severity'];
						}
					}
				}
			}
			
			if(!$results) {
				return null;
			}
			
			$result = max($results);
			if(($this->minSeverityFilter !== null && $result < $this->minSeverityFilter) || ($this->maxSeverityFilter !== null && $result > $this->maxSeverityFilter)) {
				return null;
			} else {
				return $result;
			}
		}
	}
}

?>
