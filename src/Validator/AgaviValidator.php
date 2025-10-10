<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\Validator;

use Agavi\AgaviContext;
use Agavi\Exception\AgaviConfigurationException;
use Agavi\Exception\AgaviValidatorException;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Request\AgaviWebRequest;
use Agavi\Util\AgaviArrayPathDefinition;
use Agavi\Util\AgaviParameterHolder;
use Agavi\Util\AgaviToolkit;
use Agavi\Util\AgaviVirtualArrayPath;
use Symfony\Contracts\Service\ResetInterface;

/**
 * AgaviValidator allows you to validate input
 *
 * Parameters for use in most validators:
 *   'name'       name of validator
 *   'base'       base path for validation of arrays
 *   'arguments'  an array of input parameter keys to validate
 *   'export'     destination for exported data
 *   'depends'    list of dependencies needed by the validator
 *   'provides'   list of dependencies the validator provides after success
 *   'severity'   error severity in case of failure
 *   'error'      error message when validation fails
 *   'errors'     an array of errors with the reason as key
 *   'required'   if true the validator will fail when the input parameter is 
 *                not set
 *
 * @package    agavi
 * @subpackage validator
 *
 * @author     Dominik del Bondio <ddb@bitxtender.com>
 * @author     Uwe Mesecke <uwe@mesecke.net>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
abstract class AgaviValidator extends AgaviParameterHolder implements ResetInterface
{
	/**
	 * validator field success flag
	 */
	const NOT_PROCESSED = -1;

	/**
	 * validator error severity (the validator succeeded)
	 */
	const SUCCESS = 0;

	/**
	 * validator error severity (validator failed but without impact on result
	 * of whole validation process, completely silent and does not remove the 
	 * "failed" parameters from the input parameters)
	 */
	const INFO = 100;
	
	/**
	 * validator error severity (validator failed but without impact on result
	 * of whole validation process and completely silent)
	 */
	const SILENT = 200;
	const NONE = AgaviValidator::SILENT;
	
	/**
	 * validator error severity (validator failed but without impact on result
	 * of whole validation process)
	 */
	const NOTICE = 300;

	/**
	 * validation error severity (validator failed but validation process
	 * continues)
	 */
	const ERROR = 400;

	/**
	 * validation error severity (validator failed and validation process will
	 * be aborted)
	 */
	const CRITICAL = 500;

	/**
	 * @var        AgaviContext An AgaviContext instance.
	 */
	protected $context = null;

	/**
	 * @var        AgaviIValidatorContainer parent validator container (in
	 *                                      most cases the validator manager)
	 */
	protected $parentContainer = null;

	/**
	 * @var        AgaviVirtualArrayPath The current base for input names, 
	 *                                   dependencies etc.
	 */
	protected $curBase = null;

	/**
	 * @var        string The name of this validator instance. This will either
	 *                    be the user supplied name (if any) or a random string
	 */
	protected $name = null;

	/**
	 * @var        AgaviWebRequest The parameters which should be validated
	 *                                  in the current validation run.
	 */
	protected $validationParameters = null;

	/**
	 * @var        array The name of the request parameters serving as argument to
	 *                   this validator.
	 */
	protected $arguments = [];

	/**
	 * @var        array The error messages.
	 */
	protected $errorMessages = [];

	/**
	 * @var        AgaviValidationIncident The current incident.
	 */
	protected $incident = null;
	
	/**
	 * @var        array The affected arguments of this validation run.
	 */
	protected $affectedArguments = [];

	/**
	 * Returns the base path of this validator.
	 *
	 * @return     AgaviVirtualArrayPath The basepath of this validator
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getBase()
	{
		return $this->curBase;
	}

	/**
	 * Returns the "keys" in the path of the base
	 *
	 * @return     array The keys from left to right
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getBaseKeys()
	{
		$keys = [];
		$l = $this->curBase->length();
		for($i = 1; $i < $l; ++$i) {
			$keys[] = $this->curBase->get($i);
		}

		return $keys;
	}

	/**
	 * Returns the last "keys" in the path of the base
	 *
	 * @return     mixed The key
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getLastKey()
	{
		$base = $this->curBase;
		if($base->length() == 0 || ($base->length() == 1 && $base->isAbsolute()))
			return null;

		return $base->get($base->length() - 1);
	}

	/**
	 * Returns the name of this validator.
	 *
	 * @return     string The name
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Initialize this validator.
	 *
	 * @param      AgaviContext The Context.
	 * @param      array        An array of validator parameters.
	 * @param      array        An array of argument names which should be validated.
	 * @param      array        An array of error messages.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function initialize(AgaviContext $context, array $parameters = [], array $arguments = [], array $errors = [])
	{
		$this->context = $context;

		$this->arguments = $arguments;
		$this->errorMessages = $errors;

		if(!isset($parameters['depends']) || !is_array($parameters['depends'])) {
			$parameters['depends'] = (!empty($parameters['depends'])) ? explode(' ', (string) $parameters['depends']) : [];
		}
		if(!isset($parameters['provides']) || !is_array($parameters['provides'])) {
			$parameters['provides'] = (!empty($parameters['provides'])) ? explode(' ', (string) $parameters['provides']) : [];
		}

		if(!isset($parameters['source'])) {
			$parameters['source'] = "parameters";
		}

		$this->setParameters($parameters);

		$this->name = $this->getParameter('name', AgaviToolkit::uniqid());
	}

	/**
	 * Retrieve the current application context.
	 *
	 * @return     AgaviContext The current Context instance.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Retrieve the parent container.
	 *
	 * @return     AgaviIValidatorContainer The parent container.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public final function getParentContainer()
	{
		return $this->parentContainer;
	}

	/**
	 * Sets the parent container.
	 *
	 * @param      AgaviIValidatorContainer The parent container.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function setParentContainer(AgaviIValidatorContainer $parent)
	{
		// we need a reference here, so when looping happens in a parent
		// we always have the right base
		$this->curBase = $parent->getBase();
		$this->parentContainer = $parent;
	}

	/**
	 * Validates the input.
	 *
	 * This is the method where all the validation stuff is going to happen.
	 * Inherited classes have to implement their validation logic here. It
	 * returns only true or false as validation results. The handling of
	 * error severities is done by the validator itself and should not concern
	 * the writer of a new validator.
	 *
	 * @return     bool The result of the validation.
	 *
	 * @author     Uwe Mesecke <uwe@mesecke.net>
	 * @since      0.11.0
	 */
	protected abstract function validate();

	/**
	 * Shuts the validator down.
	 *
	 * This method can be used in validators to shut down used models or
	 * other activities before the validator is killed.
	 *
	 * @see        AgaviValidationManager::shutdown()
	 *
	 * @author     Uwe Mesecke <uwe@mesecke.net>
	 * @since      0.11.0
	 */
	public function shutdown()
	{
	}

	/**
	 * Returns the specified input value.
	 *
	 * The given parameter is fetched from the request. You should _always_
	 * use this method to fetch data from the request because it pays attention
	 * to specified paths.
	 *
	 * @param      string The name of the parameter to fetch from request.
	 *
	 * @return     mixed The input value from the validation input.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function getData(?string $paramName)
	{
		$paramType = $this->getParameter('source');
		// NOTE: Parameters are fetched by value from PSR-7 request; mutation will not write back.
		$array = $this->validationParameters->getParameters($paramType);
		if ($paramName === '' || $paramName === null) {
			// Empty argument: treat the current base path itself as the value (legacy Agavi semantics for <argument></argument> with base="Foo[]")
			$value = $this->curBase->getValue($array, null);
		} else {
			$value = $this->curBase->getValueByChildPath($paramName, $array);
		}
		// Fallback: if source==parameters and value is still null, attempt direct runtime lookup
		if ($value === null && $paramType === 'parameters') {
			try {
				// getParameters(null) returns runtime overlay + intrinsic; runtime wins.
				$merged = $this->validationParameters->getParameters(null);
				if (array_key_exists($paramName, $merged)) {
					$value = $merged[$paramName];
				}
			} catch (\Throwable) {}
		}
		if (getenv('AGAVI_DEBUG_VALIDATION')) {
			AgaviDebugLogger::debug('[AgaviValidator][getData][debug] name=' . $paramName . ' source=' . $paramType . ' resolved=' . var_export($value, true), $this->getContext());
		}
		return $value;
	}

	/**
	 * Returns true if this validator has multiple arguments which need to be 
	 * validated.
	 *
	 * @return     bool Whether this validator has multiple arguments or not.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function hasMultipleArguments()
	{
		return count($this->arguments) > 1;
	}

	/**
	 * Returns the name of the argument which should be validated.
	 * Returns the name of the first (and typically only) argument by default, or,
	 * if a string is provided to the method, returns the name of the argument
	 * as configured for that identifier.
	 *
	 * @param      string The optional argument identifier, as configured.
	 *
	 * @return     string The resulting name of the argument in the request data.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * 
	 * @since      0.11.0
	 */
	protected function getArgument($name = null)
	{
		if($name === null) {
			$argNames = $this->arguments;
			reset($argNames);
			return current($argNames);
		} else {
			if(isset($this->arguments[$name])) {
				return $this->arguments[$name];
			}
		}
	}

	/**
	 * Returns all arguments which should be validated.
	 *
	 * @return     array A list of input arguments names.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function getArguments()
	{
		return $this->arguments;
	}
	
	/**
	 * Sets the arguments which should be flagged with the result of the 
	 * validator
	 * 
	 * @param      array A list of (absolute) argument names
	 * 
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function setAffectedArguments($arguments)
	{
		$this->affectedArguments = $arguments;
	}

	/**
	 * Returns whether all arguments are set in the validation input parameters.
	 * Set means anything but empty string.
	 *
	 * @param      bool Whether an error should be thrown for each missing 
	 *                  argument if this validator is required.
	 *
	 * @return     bool Whether the arguments are set.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function checkAllArgumentsSet($throwError = true)
	{
		$isRequired = $this->getParameter('required', true);
		$paramType = $this->getParameter('source');
		$result = true;

		foreach($this->getArguments() as $argument) {
			// Empty argument means current base element when using base paths (e.g. base="User[]" + <argument></argument>)
			$pName = ($argument === '' ? $this->curBase->__toString() : $this->curBase->pushRetNew($argument)->__toString());
			if (getenv('AGAVI_DEBUG_VALIDATION')) { AgaviDebugLogger::debug('[AgaviValidator][debug][checkAllArgumentsSet] validator=' . $this->getName() . ' argumentRaw=' . ($argument===''?'<empty>':$argument) . ' resolvedName=' . $pName, $this->getContext()); }
			$empty = null;
			if ($argument === '') {
				// Directly inspect current base value out of the parameter tree because isValueEmpty() cannot resolve nested bracket paths for dynamic indices.
				$array = $this->validationParameters->getParameters($paramType);
				$baseValue = $this->curBase->getValue($array, null);
				$empty = ($baseValue === null || $baseValue === '' || (is_array($baseValue) && count($baseValue) === 0));
				if (getenv('AGAVI_DEBUG_VALIDATION')) { AgaviDebugLogger::debug('[AgaviValidator][debug][checkAllArgumentsSet] emptyArgBaseInspect base=' . $this->curBase->__toString() . ' empty=' . ($empty?'1':'0') . ' baseValueType=' . gettype($baseValue), $this->getContext()); }
			} else {
				$empty = $this->validationParameters->isValueEmpty($paramType, $pName);
			}
			if($empty) {
				if($throwError && $isRequired) {
					$this->throwError('required', $pName);
				}
				$result = false;
			}
		}
		return $result;
	}

	/**
	 * Retrieves the error message for the given index with fallback. 
	 *
	 * If the given index does not exist in the error messages array, it first 
	 * checks if an unnamed error message exists and returns it or falls back the
	 * the backup message.
	 *
	 * @param      string The name of the error.
	 * @param      string The backup error message.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function getErrorMessage($index = null, $backupMessage = null)
	{
		if($index !== null && isset($this->errorMessages[$index])) {
			$error = $this->errorMessages[$index];
		} elseif(isset($this->errorMessages[''])) {
			// check if a default error exists.
			$error = $this->errorMessages[''];
		} else {
			$error = $backupMessage;
		}

		return $error;
	}

	/**
	 * Submits an error to the error manager.
	 *
	 * Will look up the index in the errors array with automatic fallback to the
	 * default error. You can optionally specify the fields affected by this 
	 * error. The error will be appended to the current incident.
	 *
	 * @param      string The name of the error parameter to fetch the message 
	 *                    from.
	 * @param      string|array The arguments which are affected by this error.
	 *                          If null is given it will affect all fields.
	 * @param      boolean Whether the argument names in $affectedArgument are
	 *                     relative or absolute.
	 * @param      boolean Whether to set the affected fields of the validator
	 *                     to the $affectedArguments
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function throwError($index = null, $affectedArgument = null, $argumentsRelative = false, $setAffected = false)
	{
		if($affectedArgument === null) {
			$affectedArguments = $this->getFullArgumentNames();
		} else {
			$affectedArguments = (array) $affectedArgument;
			if($argumentsRelative) {
				foreach($affectedArguments as &$arg) {
					$arg = $this->curBase->pushRetNew($arg)->__toString();
				}
			}
		}
		
		if($setAffected) {
			$this->affectedArguments = $affectedArguments;
		}

		$error = $this->getErrorMessage($index);

		if($this->hasParameter('translation_domain')) {
			$error = $this->getContext()->getTranslationManager()->_($error, $this->getParameter('translation_domain'));
		}

		if(!$this->incident) {
			$this->incident = new AgaviValidationIncident($this, self::mapErrorCode($this->getParameter('severity', 'error')));
		}

		foreach($affectedArguments as &$argument) {
			$argument = new AgaviValidationArgument($argument, $this->getParameter('source'));
		}
		
		if($error !== null || count($affectedArguments) != 0) {
			// don't throw empty error messages without affected fields
			$this->incident->addError(new AgaviValidationError($error, $index, $affectedArguments));
		}
	}

	/**
	 * Exports a value back into the request.
	 *
	 * Exports data into the request at the index given in the parameter
	 * 'export'. If there is no such parameter, then the method returns
	 * without exporting.
	 *
	 * Similar to getData() you should always use export() to submit data to
	 * the request because it pays attention to paths and otherwise you could
	 * overwrite stuff you don't want to.
	 *
	 * @param      mixed The value to be exported.
	 * @param      mixed An optional parameter name which should be used for
	 *                   exporting instead of the "export" attribute value, or an
	 *                   AgaviValidationArgument object if the value should be
	 *                   exported to a different source.
	 * @param      int   The result status code to use for the exported value.
	 *                   Defaults to AgaviValidator::SUCCESS.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <david.zuelke@bitextender.com>
	 * @since      0.11.0
	 */
	protected function export($value, $argument = null, $result = null)
	{
		if($argument === null) {
			$argument = $this->getParameter('export');
		}
		
		if($result === null) {
			$result = $this->getParameter('export_severity', AgaviValidator::SUCCESS);
			if(!is_numeric($result) && defined($result)) {
				$result = constant($result);
			}
		}

		if(!($argument instanceof AgaviValidationArgument) && (!is_string($argument) || $argument === '')) {
			return;
		}

		if($argument instanceof AgaviValidationArgument) {
			$source = $argument->getSource();
			$name = $argument->getName();
		} else {
			$source = $this->getParameter('export_to_source', $this->getParameter('source'));
			$name = $argument;
		}

		$array = $this->validationParameters->getParameters($source);
		$currentParts = $this->curBase->getParts();
		
		if(count($currentParts) > 0 && str_contains($name, '%')) {
			// this is a validator which actually has a base (<arguments base="xx">) set
			// and the export name contains sprintf syntax
			$name = vsprintf($name, $currentParts);
		}
		// CAUTION
		// we had a feature here during development that would allow [] at the end to append values to an array
		// that would, however, mean that we have to cast the value to an array, and, either way, a user would be able to manipulate the keys
		// example: we export to foo[], and the user supplies ?foo[28] in the URL. that means our export will be in foo[29]. foo[28] will be removed by the validation, but the keys are still potentially harmful
		// that's why we decided to remove this again
		$cp = new AgaviVirtualArrayPath($name);
		$cp->setValue($array, $value);

		// Persist export into request runtime parameters (post-migration fix):
		// Extend: also materialize bracketed exports into a nested runtime structure so actions accessing $request->getParameter('User') receive array of exported values.
		$rootParameterName = null;
		try {
			if(method_exists($this->validationParameters, 'setParameter')) {
				$flatName = $cp->__toString();
				if(strpos($flatName, '[') === false) {
					$this->validationParameters->setParameter($flatName, $value);
					if (getenv('AGAVI_DEBUG_VALIDATION')) { AgaviDebugLogger::debug('[AgaviValidator][export][debug] stored simple name=' . $flatName . ' type=' . (is_object($value)?get_class($value):gettype($value)), $this->getContext()); }
				} else {
					// Parse root and indices: e.g. User[0] => root=User, indices=[0]
					$root = substr($flatName, 0, strpos($flatName, '['));
					$indicesPart = substr($flatName, strlen($root));
					if($root !== '') {
						$indices = [];
						if(preg_match_all('/\[(.*?)\]/', $indicesPart, $m)) {
							foreach($m[1] as $seg) { $indices[] = $seg; }
						}
						// Build nested array reference in runtime parameters
						$runtime = $this->validationParameters->getParameters('runtime');
						if(!isset($runtime[$root]) || !is_array($runtime[$root])) { $runtime[$root] = []; }
						$ref =& $runtime[$root];
						if(count($indices) > 0) {
							$lastIndex = array_pop($indices);
							foreach($indices as $idx) {
								if($idx === '') { $ref[] = []; end($ref); $idx = key($ref); }
								if(!isset($ref[$idx]) || !is_array($ref[$idx])) { $ref[$idx] = []; }
								$ref =& $ref[$idx];
							}
							if($lastIndex === '') { $ref[] = $value; }
							else { $ref[$lastIndex] = $value; }
						}
						// Write back updated root array into runtime parameters
						$this->validationParameters->setParameter($root, $runtime[$root]);
						// PHASE 3 FIX: Remember root parameter name so we can register it as succeeded argument
						$rootParameterName = $root;
						if (getenv('AGAVI_DEBUG_VALIDATION')) { AgaviDebugLogger::debug('[AgaviValidator][export][debug] stored bracketed root=' . $root . ' flat=' . $flatName, $this->getContext()); }
					}
				}
			}
		} catch(\Throwable) {}
		if($this->parentContainer !== null) {
			// make sure the parameter doesn't get removed by the validation manager
			if(is_array($value)) {
				// for arrays all child elements need to be marked as not processed
				foreach(AgaviArrayPathDefinition::getFlatKeyNames($value) as $keyName) {
					$this->parentContainer->addArgumentResult(new AgaviValidationArgument($cp->pushRetNew($keyName)->__toString(), $source), $result, $this);
				}
			}
			$this->parentContainer->addArgumentResult(new AgaviValidationArgument($cp->__toString(), $source), $result, $this);
			
			// PHASE 3 FIX: Also register the root parameter (e.g. 'User') as a succeeded argument
			// when we export to bracketed names (e.g. 'User[0]'). This prevents the pruning logic
			// from removing the root array parameter that we just created.
			if($rootParameterName !== null && $rootParameterName !== '') {
				$this->parentContainer->addArgumentResult(new AgaviValidationArgument($rootParameterName, $source), $result, $this);
				if (getenv('AGAVI_DEBUG_VALIDATION')) { AgaviDebugLogger::debug('[AgaviValidator][export][debug] registered root argument=' . $rootParameterName . ' to prevent pruning', $this->getContext()); }
			}
		}

		// Always-on whitelist: ensure exported parameter key is whitelisted immediately
		try {
			if(method_exists($this->validationParameters, 'enforceValidatedParameters')) {
				$names = [$cp->__toString()];
				if($rootParameterName) { $names[] = $rootParameterName; }
				$this->validationParameters->enforceValidatedParameters($names);
			}
		} catch(\Throwable) { }
	}

	/**
	 * Validates this validator in the given base.
	 *
	 * @param      AgaviVirtualArrayPath The base in which the input should be 
	 *                                   validated.
	 *
	 * @return     int AgaviValidator::SUCCESS if validation succeeded or given
	 *                 error severity.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     Uwe Mesecke <uwe@mesecke.net>
	 * @since      0.11.0
	 */
	protected function validateInBase(AgaviVirtualArrayPath $base)
	{
		$base = clone $base;
		if($base->length() == 0) {
			// we have an empty base so we do the actual validation
			if (getenv('AGAVI_DEBUG_VALIDATION')) {
				$argList = $this->getArguments();
				$argExport = [];
				foreach($argList as $a){ $argExport[] = $a === '' ? "<empty>" : $a; }
				AgaviDebugLogger::debug('[AgaviValidator][debug][pre-validate] name=' . $this->getName() . ' curBase=' . ($this->curBase?->__toString() ?? '') . ' args=' . implode(',', $argExport), $this->getContext());
			}
			if($this->getDependencyManager() && (count($this->getParameter('depends')) > 0 && !$this->getDependencyManager()->checkDependencies($this->getParameter('depends'), $this->curBase))) {
				// dependencies not met, exit with success
				return self::NOT_PROCESSED;
			}

			$this->affectedArguments = $this->getFullArgumentNames();

			$result = self::SUCCESS;
			$errorCode = self::mapErrorCode($this->getParameter('severity', 'error'));

			$allArgsSet = $this->checkAllArgumentsSet(false);
			if (getenv('AGAVI_DEBUG_VALIDATION')) {
				AgaviDebugLogger::debug('[AgaviValidator][debug][postCheckAllArgs] validator=' . $this->getName() . ' allArgsSet=' . ($allArgsSet ? 'true' : 'false'), $this->getContext());
			}
			if($allArgsSet) {
				if (getenv('AGAVI_DEBUG_VALIDATION')) {
					AgaviDebugLogger::debug('[AgaviValidator][debug][callingValidate] validator=' . $this->getName(), $this->getContext());
				}
				try {
					$validateResult = $this->validate();
					if (getenv('AGAVI_DEBUG_VALIDATION')) {
						AgaviDebugLogger::debug('[AgaviValidator][debug][postValidate] validator=' . $this->getName() . ' result=' . ($validateResult ? 'true' : 'false'), $this->getContext());
					}
				} catch (\Throwable $e) {
					if (getenv('AGAVI_DEBUG_VALIDATION')) {
						AgaviDebugLogger::debug('[AgaviValidator][debug][validateException] validator=' . $this->getName() . ' exception=' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), $this->getContext());
					}
					throw $e;
				}
				if(!$validateResult) {
					// validation failed, exit with configured error code
					$result = $errorCode;
				}
			} else {
				if($this->getParameter('required', true)) {
					$this->throwError('required');
					$result = $errorCode;
				} else {
					// we don't throw an error here because this is not an incident per se
					// but rather a non validated field
					$result = self::NOT_PROCESSED;
				}
			}

			if($this->parentContainer !== null) {
				foreach($this->affectedArguments as $fieldname) {
					$this->parentContainer->addArgumentResult(new AgaviValidationArgument($fieldname, $this->getParameter('source')), $result, $this);
				}

				if($this->incident) {
					$this->parentContainer->addIncident($this->incident);
				}
			}

			$this->incident = null;
			// put dependencies provided by this validator into manager
			if($this->getDependencyManager() && ($result == self::SUCCESS && count($this->getParameter('provides')) > 0)) {
				$this->getDependencyManager()->addDependTokens($this->getParameter('provides'), $this->curBase);
			}
			return $result;

		} elseif($base->left() !== '') {
			/*
			 * the next component in the base is no wildcard so we
			 * just put it into our own base and validate further
			 * into the base.
			 */

			$this->curBase->push($base->shift());
			$ret = $this->validateInBase($base);
			$this->curBase->pop();

			return $ret;

		} else {
			/*
			 * now we have a wildcard as next component so we collect
			 * all defined value names in the request at the path
			 * specified by our own base and validate in each of that
			 * names
			 */
			$names = $this->getKeysInCurrentBase();

			// if the names array is empty this means we need to throw an error since
			// this means the input doesn't exist
			if(count($names) == 0) {
				if($this->getDependencyManager() && (count($this->getParameter('depends')) > 0 && !$this->getDependencyManager()->checkDependencies($this->getParameter('depends'), $this->curBase))) {
					// since the dependencies are only ever checked if the base gets empty (which happens when
					// the validation is about to validate an argument), but we are already bailing out in an earlier
					// stage, lets do the dependency check so the validator doesn't accidently return an error even
					// if it's dependencies aren't met
					return self::NOT_PROCESSED;
				} else {
					if($this->getParameter('required', true)) {
						$this->throwError('required');
						return self::mapErrorCode($this->getParameter('severity', 'error'));
					} else {
						return self::NOT_PROCESSED;
					}
				}
			}

			// throw the wildcard away
			$base->shift();

			$ret = self::NOT_PROCESSED;

			// validate in every name defined in the request
			foreach($names as $name) {
				$newBase = clone $base;
				$newBase->unshift($name);
				$t = $this->validateInBase($newBase);

				if($t == self::CRITICAL) {
					return $t;
				}

				// remember the highest error severity
				$ret = max($ret, $t);
			}

			return $ret;
		}
	}

	/**
	 * Executes the validator.
	 *
	 * @param      AgaviWebRequest The data which should be validated.
	 *
	 * @return     int The validation result (see severity constants).
	 *
	 * @author     Uwe Mesecke <uwe@mesecke.net>
	 * @since      0.11.0
	 */
	public function execute(AgaviWebRequest $parameters)
	{
		if($this->getParameter('source') != "parameters" && !in_array($this->getParameter('source'), ["parameters", "files", "headers", "cookies"])) {
			throw new AgaviConfigurationException('Unknown source "' . $this->getParameter('source') . '" specified in validator ' . $this->getName());
		}

		$this->validationParameters = $parameters;
		$base = new AgaviVirtualArrayPath($this->getParameter('base'));

		$res = $this->validateInBase($base);
		if($this->incident && $this->parentContainer) {
			$this->parentContainer->addIncident($this->incident);
			$this->incident = null;
		}
		return $res;
	}

	/**
	 * Converts string severity codes into integer values
	 * (see severity constants)
	 *
	 * critical -> AgaviValidator::CRITICAL
	 * error    -> AgaviValidator::ERROR
	 * notice   -> AgaviValidator::NOTICE
	 * none     -> AgaviValidator::NONE
	 * success  -> not allowed to be specified by the user.
	 *
	 * @param      string The error severity as string.
	 *
	 * @return     int The error severity as in (see severity constants).
	 *
	 * @throws     <b>AgaviValidatorException</b> if the input was no known 
	 *                                           severity
	 *
	 * @author     Uwe Mesecke <uwe@mesecke.net>
	 * @since      0.11.0
	 */
	public static function mapErrorCode($code)
	{
		return match (strtolower((string) $code)) {
            'critical' => self::CRITICAL,
            'error' => self::ERROR,
            'notice' => self::NOTICE,
            'none', 'silent' => self::SILENT,
            'info' => self::INFO,
            default => throw new AgaviValidatorException('unknown error code: '.$code),
        };
	}

	/**
	 * Returns all available keys in the currently set base.
	 *
	 * @return     array The available keys.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function getKeysInCurrentBase()
	{
		$paramType = $this->getParameter('source');

		$array = $this->validationParameters->getParameters($paramType);
		$names = $this->curBase->getValue($array, []);
		if (getenv('AGAVI_DEBUG_VALIDATION')) {
			AgaviDebugLogger::debug('[AgaviValidator][debug][getKeysInCurrentBase] base=' . $this->curBase->__toString() . ' keys=' . (is_array($names)?implode(',', array_keys($names)):'<non-array>'), $this->getContext());
		}

		return is_array($names) ? array_keys($names) : [];
	}

	/**
	 * Returns all arguments with their full path.
	 *
	 * @return     array The arguments.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function getFullArgumentNames()
	{
		$arguments = [];
		foreach($this->getArguments() as $argument) {
			if($argument) {
				$arguments[] = $this->curBase->pushRetNew($argument)->__toString();
			} else {
				$arguments[] = $this->curBase->__toString();
			}
		}

		return $arguments;
	}

	/**
	 * Returns the depency manager of the parent container if any.
	 * 
	 * @return     AgaviDependencyManager The parent's dependency manager.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getDependencyManager()
	{
		if($this->parentContainer instanceof AgaviIValidatorContainer) {
			return $this->parentContainer->getDependencyManager();
		}
		return null;
	}

	public function reset(): void
	{
		$this->context = null;
		$this->parentContainer = null;
		$this->curBase = null;
		$this->name = null;
		$this->validationParameters = null;
		$this->arguments = [];
		$this->errorMessages = [];
		$this->incident = null;
		$this->affectedArguments = [];
		
		parent::reset();
	}
}

?>