<?php
namespace Quiote\Validator;

/**
 * IValidationReportQuery allows queries against the validation run report.
 * @since      1.0.0
 * @version    1.0.0
 */
interface IValidationReportQuery
{
	/**
	 * Returns a new IValidationReportQuery which returns only the incidents
	 * for the given argument (and the other existing filter rules).
	 * @param      ValidationArgument|string|array $argument The argument instance, or
	 *                                                  a parameter name, or an
	 *                                                  array of these elements.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byArgument($argument);
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * for the given validator (and the other existing filter rules).
	 * @param      string|array $name The name of the validator, or an array of names.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byValidator($name);
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * for the given error name (and the other existing filter rules).
	 * @param      string|array $name The name of the error, or an array of names.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byErrorName($name);
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * of the given severity or higher (and the other existing filter rules).
	 * @param      int $minSeverity The minimum severity.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byMinSeverity($minSeverity);
	
	/**
	 * Returns a new IValidationReportQuery which contains only the incidents
	 * of the given severity or lower (and the other existing filter rules).
	 * @param      int $maxSeverity The maximum severity.
	 * @return     IValidationReportQuery
	 * @since      1.0.0
	 */
	public function byMaxSeverity($maxSeverity);
	
	/**
	 * Retrieves all incidents which match the currently defined filter rules.
	 * @return     array An array of ValidationIncident objects.
	 * @since      1.0.0
	 */
	public function getIncidents();
	
	/**
	 * Retrieves all ValidationError objects which match the currently
	 * defined filter rules.
	 * @return     array An array of ValidationError objects.
	 * @since      1.0.0
	 */
	public function getErrors();
	
	/**
	 * Retrieves all error messages which match the currently defined filter
	 * rules.
	 * @return     array An array of message strings.
	 * @since      1.0.0
	 */
	public function getErrorMessages();

	/**
	 * Retrieves all error messages together with the fields that caused them,
	 * matching the currently defined filter rules.
	 * Each entry has the form array('message' => string, 'errors' => string[]),
	 * i.e. the same structure the (deprecated) ValidationManager::getErrorMessages()
	 * returns — provided here as a non-deprecated report-query accessor so callers
	 * that need the field annotation don't have to reach for the deprecated method.
	 * @return     array An array of array('message' => string, 'errors' => array).
	 * @since      1.0.0
	 */
	public function getErrorMessagesWithFields();

	/**
	 * Retrieves all ValidationArgument objects which match the currently
	 * defined filter rules.
	 * @return     array An array of ValidationArgument objects.
	 * @since      1.0.0
	 */
	public function getArguments();
	
	/**
	 * Check if there are any incidents matching the currently defined filter
	 * rules.
	 * @return     bool Whether or not any incidents exist for the currently
	 *                  defined filter rules.
	 * @since      1.0.0
	 */
	public function has();
	
	/**
	 * Get the number of incidents matching the currently defined filter rules.
	 * @return     int The number of incidents matching the currently defined
	 *                 filter rules.
	 * @since      1.0.0
	 */
	public function count();
	
	/**
	 * Retrieves the highest validation result code of the collection composed of
	 * the currently defined filter rules.
	 * @return     ?int An Validator::* severity constant, or null if there is
	 *                 no result for this filter combination. Please remember to
	 *                 do a strict === comparison if you are comparing against
	 *                 Validator::SUCCESS.	 */
	public function getResult();
}

?>