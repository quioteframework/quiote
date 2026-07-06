<?php
namespace Quiote\Validator;

use Quiote\Context;
use Quiote\Exception\ConfigurationException;
use Quiote\Exception\ValidatorException;
use Quiote\Request\WebRequest;
use Quiote\Util\ArrayPathDefinition;
use Quiote\Util\ParameterHolder;
use Quiote\Util\Toolkit;
use Quiote\Util\VirtualArrayPath;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Validator allows you to validate input
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
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class Validator extends ParameterHolder implements ResetInterface
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
	const NONE = Validator::SILENT;
	
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
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;

	/**
	 * @var        ?IValidatorContainer parent validator container (in
	 *                                      most cases the validator manager)
	 */
	protected $parentContainer = null;

	/**
	 * @var        ?\Quiote\Util\VirtualArrayPath The current base for input names, 
	 *                                   dependencies etc.
	 */
	protected $curBase = null;

	/**
	 * @var        ?string The name of this validator instance. This will either
	 *                    be the user supplied name (if any) or a random string
	 */
	protected $name = null;

	/**
	 * @var        ?WebRequest The parameters which should be validated
	 *                                  in the current validation run.
	 */
	protected $validationParameters = null;

	/**
	 * @var        array<int|string, mixed> The name of the request parameters serving as argument to
	 *                   this validator.
	 */
	protected $arguments = [];

	/**
	 * @var        array<string, string> The error messages.
	 */
	protected $errorMessages = [];

	/**
	 * @var        ?ValidationIncident The current incident.
	 */
	protected $incident = null;
	
	/**
	 * @var        array<int, mixed> The affected arguments of this validation run.
	 */
	protected $affectedArguments = [];

	/**
	 * Returns the base path of this validator.
	 * @return     \Quiote\Util\VirtualArrayPath The basepath of this validator
	 * @since      1.0.0
	 */
	public function getBase()
	{
		return $this->curBase;
	}

	/**
	 * Returns the "keys" in the path of the base
	 * @return     array<int, mixed> The keys from left to right
	 * @since      1.0.0
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
	 * @return     mixed The key
	 * @since      1.0.0
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
	 * @return     ?string The name
	 * @since      1.0.0
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Returns the set of parameter names this validator understands.
	 * ValidatorConfigHandler uses this to reject unknown/misspelled
	 * attributes and <ae:parameter> names at config-compile time instead of
	 * silently absorbing and ignoring them (see the SecureStringValidator
	 * `values` incident: a nonexistent allowlist attribute was silently
	 * stored and never enforced).
	 *
	 * This base set covers every parameter the base Validator class itself
	 * reads (directly or via getAttributes() picking up structural XML
	 * attributes like 'class'/'name'/'method'). Subclasses that accept
	 * additional parameters MUST override this and merge onto the parent
	 * set — never replace it outright.
	 * @return     array<int, string> The accepted parameter names.
	 * @since      1.0.0
	 */
	public static function getAcceptedParameters(): array
	{
		return [
			// structural XML attributes that land in the parameter bag via
			// XmlConfigDomElement::getAttributes() even though they're
			// consumed elsewhere in the compile pipeline, not by the
			// validator instance itself
			'name', 'class', 'method',
			// input source / path
			'base', 'source',
			// dependency graph
			'depends', 'provides',
			// outcome / severity
			'severity', 'required',
			// export
			'export', 'export_severity', 'export_to_source',
			// i18n
			'translation_domain',
		];
	}

	/**
	 * Initialize this validator.
	 * @param      Context $context The Context.
	 * @param      array<string, mixed> $parameters An array of validator parameters.
	 * @param      array<int|string, mixed> $arguments An array of argument names which should be validated.
	 * @param      array<string, string> $errors An array of error messages.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [], array $arguments = [], array $errors = [])
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

		$this->name = $this->getParameter('name', Toolkit::uniqid());
	}

	/**
	 * Retrieve the current application context.
	 * @return     Context The current Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Retrieve the parent container.
	 * @return     ?IValidatorContainer The parent container.
	 * @since      1.0.0
	 */
	public final function getParentContainer()
	{
		return $this->parentContainer;
	}

	/**
	 * Sets the parent container.
	 * @param      IValidatorContainer $parent The parent container.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setParentContainer(IValidatorContainer $parent)
	{
		// we need a reference here, so when looping happens in a parent
		// we always have the right base
		//
		// IValidatorContainer::getBase() is documented with an unqualified
		// "VirtualArrayPath" return type, which resolves to the (nonexistent)
		// Quiote\Validator\VirtualArrayPath rather than Quiote\Util\VirtualArrayPath
		// actually returned by every implementation. Route through a
		// mixed-typed boundary so that stale contract doesn't propagate here.
		$this->curBase = $this->coerceBase($parent->getBase());
		$this->parentContainer = $parent;
	}

	/**
	 * Narrows an arbitrary value from IValidatorContainer::getBase() (whose
	 * documented return type does not match its real implementations, see
	 * setParentContainer()) down to the actual VirtualArrayPath type used
	 * throughout this class.
	 * @param      mixed $base
	 * @return     ?VirtualArrayPath
	 * @since      1.0.0
	 */
	private function coerceBase(mixed $base): ?VirtualArrayPath
	{
		return $base instanceof VirtualArrayPath ? $base : null;
	}

	/**
	 * Validates the input.
	 * This is the method where all the validation stuff is going to happen.
	 * Inherited classes have to implement their validation logic here. It
	 * returns only true or false as validation results. The handling of
	 * error severities is done by the validator itself and should not concern
	 * the writer of a new validator.
	 * @return     bool The result of the validation.
	 * @since      1.0.0
	 */
	protected abstract function validate();

	/**
	 * Shuts the validator down.
	 * This method can be used in validators to shut down used models or
	 * other activities before the validator is killed.
	 * @see        ValidationManager::shutdown()
	 * @return     void
	 * @since      1.0.0
	 */
	public function shutdown()
	{
	}

	/**
	 * Returns the specified input value.
	 * The given parameter is fetched from the request. You should _always_
	 * use this method to fetch data from the request because it pays attention
	 * to specified paths.
	 * @param      string $paramName The name of the parameter to fetch from request.
	 * @return     mixed The input value from the validation input.
	 * @since      1.0.0
	 */
	protected function getData(?string $paramName)
	{
		$paramType = $this->getParameter('source');
		// NOTE: Parameters are fetched by value from PSR-7 request; mutation will not write back.
		$array = $this->validationParameters->getParameters($paramType);
		if ($paramName === '' || $paramName === null) {
			// Empty argument: treat the current base path itself as the value (legacy Quiote semantics for <argument></argument> with base="Foo[]")
			$value = $this->curBase->getValue($array, null);
		} else {
			$value = $this->curBase->getValueByChildPath($paramName, $array);
		}
		// PSR-7 header handling: getHeaders() returns original casing and array values.
		// 1. Case-insensitive lookup when the exact key didn't match.
		// 2. Unwrap single-element arrays to scalar (matching getHeaderLine() semantics)
		//    so that string validators work naturally with header values.
		if ($paramType === 'headers') {
			if ($value === null && $paramName !== null) {
				$lowerName = strtolower($paramName);
				foreach ($array as $key => $val) {
					if (strtolower((string) $key) === $lowerName) {
						$value = $val;
						break;
					}
				}
			}
			if (is_array($value)) {
				$value = implode(', ', $value);
			}
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
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$resolvedStr = match(true) {
				is_object($value) => $value::class,
				is_null($value) => 'NULL',
				is_scalar($value) => gettype($value) . ':' . (string)$value,
				is_array($value) => 'array(' . count($value) . ')',
				default => gettype($value),
			};
			$logger->debug('[Validator][getData][debug] name=' . $paramName . ' source=' . $paramType . ' resolved=' . $resolvedStr);
		}
		return $value;
	}

	/**
	 * Returns true if this validator has multiple arguments which need to be 
	 * validated.
	 * @return     bool Whether this validator has multiple arguments or not.
	 * @since      1.0.0
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
	 * @param      string $name The optional argument identifier, as configured.
	 * @return     ?string The resulting name of the argument in the request data,
	 *                   or null if no argument is registered under that identifier.
	 * @since      1.0.0
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

		return null;
	}

	/**
	 * Returns all arguments which should be validated.
	 * @return     array<int|string, mixed> A list of input arguments names.
	 * @since      1.0.0
	 */
	protected function getArguments()
	{
		return $this->arguments;
	}

	/**
	 * Sets the arguments which should be flagged with the result of the
	 * validator
	 * @param      array<int, mixed> $arguments A list of (absolute) argument names
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setAffectedArguments($arguments)
	{
		$this->affectedArguments = $arguments;
	}

	/**
	 * Returns whether all arguments are set in the validation input parameters.
	 * Set means anything but empty string.
	 * @param      bool $throwError Whether an error should be thrown for each missing 
	 *                  argument if this validator is required.
	 * @return     bool Whether the arguments are set.
	 * @since      1.0.0
	 */
	protected function checkAllArgumentsSet($throwError = true)
	{
		$isRequired = $this->getParameter('required', true);
		$paramType = $this->getParameter('source');
		$result = true;

		foreach($this->getArguments() as $argument) {
			// Empty argument means current base element when using base paths (e.g. base="User[]" + <argument></argument>)
			$pName = ($argument === '' ? $this->curBase->__toString() : $this->curBase->pushRetNew($argument)->__toString());
			$logger = \Quiote\Logging\Log::for($this);
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][debug][checkAllArgumentsSet] validator=' . $this->getName() . ' argumentRaw=' . ($argument===''?'<empty>':$argument) . ' resolvedName=' . $pName); }
			$empty = null;
			if ($argument === '') {
				// Directly inspect current base value out of the parameter tree because isValueEmpty() cannot resolve nested bracket paths for dynamic indices.
				$array = $this->validationParameters->getParameters($paramType);
				$baseValue = $this->curBase->getValue($array, null);
				$empty = ($baseValue === null || $baseValue === '' || (is_array($baseValue) && count($baseValue) === 0));
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][debug][checkAllArgumentsSet] emptyArgBaseInspect base=' . $this->curBase->__toString() . ' empty=' . ($empty?'1':'0') . ' baseValueType=' . gettype($baseValue)); }
			} else {
				try {
					$empty = $this->validationParameters->isValueEmpty($paramType, $pName);
				} catch (\Throwable $e) {
					if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][debug][checkAllArgumentsSet] EXCEPTION in isValueEmpty: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()); }
					throw $e;
				}
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
	 * Sets an error message override for the given index (the empty string
	 * is the default/generic message). Exists for programmatic validator
	 * registration (see Quiote\Validator\Compiler\Runtime\ValidatorSpec)
	 * where errors aren't known until after initialize() has already run.
	 * @param      string $index The error index ('' for the default message).
	 * @param      string $message The error message.
	 * @since      1.0.0
	 */
	public function setErrorMessage(string $index, string $message): void
	{
		$this->errorMessages[$index] = $message;
	}

	/**
	 * Retrieves the error message for the given index with fallback.
	 * If the given index does not exist in the error messages array, it first 
	 * checks if an unnamed error message exists and returns it or falls back the
	 * the backup message.
	 * @param      string $index The name of the error.
	 * @param      string $backupMessage The backup error message.
	 * @return     ?string
	 * @since      1.0.0
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
	 * Will look up the index in the errors array with automatic fallback to the
	 * default error. You can optionally specify the fields affected by this 
	 * error. The error will be appended to the current incident.
	 * @param      string $index The name of the error parameter to fetch the message 
	 *                    from.
	 * @param      string|array<int, mixed> $affectedArgument The arguments which are affected by this error.
	 *                          If null is given it will affect all fields.
	 * @param      boolean $argumentsRelative Whether the argument names in $affectedArgument are
	 *                     relative or absolute.
	 * @param      boolean $setAffected Whether to set the affected fields of the validator
	 *                     to the $affectedArguments
	 * @return     void
	 * @since      1.0.0
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
			$this->incident = new ValidationIncident($this, self::mapErrorCode($this->getParameter('severity', 'error')));
		}

		foreach($affectedArguments as &$argument) {
			$argument = new ValidationArgument($argument, $this->getParameter('source'));
		}
		
		if($error !== null || count($affectedArguments) != 0) {
			// don't throw empty error messages without affected fields
			$this->incident->addError(new ValidationError($error, $index, $affectedArguments));
		}
	}

	/**
	 * Exports a value back into the request.
	 * Exports data into the request at the index given in the parameter
	 * 'export'. If there is no such parameter, then the method returns
	 * without exporting.
	 * Similar to getData() you should always use export() to submit data to
	 * the request because it pays attention to paths and otherwise you could
	 * overwrite stuff you don't want to.
	 * @param      mixed $value The value to be exported.
	 * @param      mixed $argument An optional parameter name which should be used for
	 *                   exporting instead of the "export" attribute value, or an
	 *                   ValidationArgument object if the value should be
	 *                   exported to a different source.
	 * @param      int $result The result status code to use for the exported value.
	 *                   Defaults to Validator::SUCCESS.
	 * @return     void
	 * @since      1.0.0
	 */
	protected function export($value, $argument = null, $result = null)
	{
		if($argument === null) {
			$argument = $this->getParameter('export');
		}
		
		if($result === null) {
			$result = $this->getParameter('export_severity', Validator::SUCCESS);
			if(!is_numeric($result) && defined($result)) {
				$result = constant($result);
			}
		}

		if(!($argument instanceof ValidationArgument) && (!is_string($argument) || $argument === '')) {
			return;
		}

		if($argument instanceof ValidationArgument) {
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
		$cp = new VirtualArrayPath($name);
		$cp->setValue($array, $value);

		// Persist export into request runtime parameters (post-migration fix):
		// Extend: also materialize bracketed exports into a nested runtime structure so actions accessing $request->getParameter('User') receive array of exported values.
		$rootParameterName = null;
		try {
			if(method_exists($this->validationParameters, 'setParameter')) {
				$flatName = $cp->__toString();
				if(!str_contains($flatName, '[')) {
					$this->validationParameters->setParameter($flatName, $value);
					if (($logger = \Quiote\Logging\Log::for($this))->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][export][debug] stored simple name=' . $flatName . ' type=' . (get_debug_type($value))); }
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
								if($idx === '') { $ref[] = []; $idx = array_key_last($ref); }
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
						if (($logger = \Quiote\Logging\Log::for($this))->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][export][debug] stored bracketed root=' . $root . ' flat=' . $flatName); }
					}
				}
			}
		} catch(\Throwable) {}
		if($this->parentContainer !== null) {
			// make sure the parameter doesn't get removed by the validation manager
			if(is_array($value)) {
				// for arrays all child elements need to be marked as not processed
				foreach(ArrayPathDefinition::getFlatKeyNames($value) as $keyName) {
					$this->parentContainer->addArgumentResult(new ValidationArgument($cp->pushRetNew($keyName)->__toString(), $source), $result, $this);
				}
			}
			$this->parentContainer->addArgumentResult(new ValidationArgument($cp->__toString(), $source), $result, $this);
			
			// PHASE 3 FIX: Also register the root parameter (e.g. 'User') as a succeeded argument
			// when we export to bracketed names (e.g. 'User[0]'). This prevents the pruning logic
			// from removing the root array parameter that we just created.
			if($rootParameterName !== null) {
				$this->parentContainer->addArgumentResult(new ValidationArgument($rootParameterName, $source), $result, $this);
				if (($logger = \Quiote\Logging\Log::for($this))->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][export][debug] registered root argument=' . $rootParameterName . ' to prevent pruning'); }
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
	 * @param      \Quiote\Util\VirtualArrayPath $base The base in which the input should be 
	 *                                   validated.
	 * @return     int Validator::SUCCESS if validation succeeded or given
	 *                 error severity.
	 * @since      1.0.0
	 */
	protected function validateInBase(VirtualArrayPath $base)
	{
		$base = clone $base;
		$logger = \Quiote\Logging\Log::for($this);
		if($base->length() == 0) {
			// we have an empty base so we do the actual validation
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$argList = $this->getArguments();
				$argExport = [];
				foreach($argList as $a){ $argExport[] = $a === '' ? "<empty>" : $a; }
				$logger->debug('[Validator][debug][pre-validate] name=' . $this->getName() . ' curBase=' . ($this->curBase?->__toString() ?? '') . ' args=' . implode(',', $argExport));
			}
			if($this->getDependencyManager() && (count($this->getParameter('depends')) > 0 && !$this->getDependencyManager()->checkDependencies($this->getParameter('depends'), $this->curBase))) {
				// dependencies not met, exit with success
				return self::NOT_PROCESSED;
			}

			$this->affectedArguments = $this->getFullArgumentNames();

			$result = self::SUCCESS;
			$errorCode = self::mapErrorCode($this->getParameter('severity', 'error'));

			$allArgsSet = $this->checkAllArgumentsSet(false);
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[Validator][debug][postCheckAllArgs] validator=' . $this->getName() . ' allArgsSet=' . ($allArgsSet ? 'true' : 'false'));
			}
			if($allArgsSet) {
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[Validator][debug][callingValidate] validator=' . $this->getName());
				}
				try {
					$validateResult = $this->validate();
					if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
						$logger->debug('[Validator][debug][postValidate] validator=' . $this->getName() . ' result=' . ($validateResult ? 'true' : 'false'));
					}
				} catch (\Throwable $e) {
					if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
						$logger->debug('[Validator][debug][validateException] validator=' . $this->getName() . ' exception=' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
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
					$this->parentContainer->addArgumentResult(new ValidationArgument($fieldname, $this->getParameter('source')), $result, $this);
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
	 * @param      WebRequest $parameters The data which should be validated.
	 * @return     int The validation result (see severity constants).
	 * @since      1.0.0
	 */
	public function execute(WebRequest $parameters)
	{
		if($this->getParameter('source') != "parameters" && !in_array($this->getParameter('source'), ["parameters", "files", "headers", "cookies"])) {
			throw new ConfigurationException('Unknown source "' . $this->getParameter('source') . '" specified in validator ' . $this->getName());
		}

		$this->validationParameters = $parameters;
		$base = new VirtualArrayPath($this->getParameter('base'));

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
	 * critical -> Validator::CRITICAL
	 * error    -> Validator::ERROR
	 * notice   -> Validator::NOTICE
	 * none     -> Validator::NONE
	 * success  -> not allowed to be specified by the user.
	 * @param      string $code The error severity as string.
	 * @return     int The error severity as in (see severity constants).
	 * @throws     ValidatorException if the input was no known 
	 *                                           severity
	 * @since      1.0.0
	 */
	public static function mapErrorCode($code)
	{
		return match (strtolower((string) $code)) {
            'critical' => self::CRITICAL,
            'error' => self::ERROR,
            'notice' => self::NOTICE,
            'none', 'silent' => self::SILENT,
            'info' => self::INFO,
            default => throw new ValidatorException('unknown error code: '.$code),
        };
	}

	/**
	 * Returns all available keys in the currently set base.
	 * @return     array<int, mixed> The available keys.
	 * @since      1.0.0
	 */
	protected function getKeysInCurrentBase()
	{
		$paramType = $this->getParameter('source');

		$array = $this->validationParameters->getParameters($paramType);
		$names = $this->curBase->getValue($array, []);
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[Validator][debug][getKeysInCurrentBase] base=' . $this->curBase->__toString() . ' keys=' . (is_array($names)?implode(',', array_keys($names)):'<non-array>'));
		}

		return is_array($names) ? array_keys($names) : [];
	}

	/**
	 * Returns all arguments with their full path.
	 * @return     array<int, string> The arguments.
	 * @since      1.0.0
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
	 * @return     ?DependencyManager The parent's dependency manager.
	 * @since      1.0.0
	 */
	public function getDependencyManager()
	{
		if($this->parentContainer instanceof IValidatorContainer) {
			return $this->parentContainer->getDependencyManager();
		}
		return null;
	}

	#[\Override]
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