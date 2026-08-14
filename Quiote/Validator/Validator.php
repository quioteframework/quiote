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
abstract class Validator extends ParameterHolder implements ResetInterface, ValidatorInterface
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
	 * @var        ?Context An Context instance. Set by initialize(); cleared
	 *                     by reset() while this instance sits in a reuse pool
	 *                     between validation runs. getContext() below fails
	 *                     loudly rather than returning null so callers never
	 *                     need to null-check it.
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
	 * @var        array<int|string, string> The name of the request parameters serving as argument to
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
	 * @var        array<int, string> The affected arguments of this validation run.
	 */
	protected $affectedArguments = [];

	/**
	 * Returns the base path of this validator.
	 * @return     \Quiote\Util\VirtualArrayPath The basepath of this validator
	 * @since      1.0.0
	 */
	public function getBase()
	{
		return $this->requireCurBase();
	}

	/**
	 * Returns the "keys" in the path of the base
	 * @return     array<int, mixed> The keys from left to right
	 * @since      1.0.0
	 */
	public function getBaseKeys()
	{
		$base = $this->requireCurBase();
		$keys = [];
		$l = $base->length();
		for($i = 1; $i < $l; ++$i) {
			$keys[] = $base->get($i);
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
		$base = $this->requireCurBase();
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

		foreach($arguments as $argument) {
			if(!is_string($argument)) {
				throw new ConfigurationException('Validator argument names must be strings, ' . get_debug_type($argument) . ' given.');
			}
		}
		$this->arguments = $arguments;
		$this->errorMessages = $errors;

		foreach(['depends', 'provides'] as $listParam) {
			if(isset($parameters[$listParam]) && is_array($parameters[$listParam])) {
				continue;
			}
			$rawValue = $parameters[$listParam] ?? null;
			if(empty($rawValue)) {
				$parameters[$listParam] = [];
			} elseif(!is_string($rawValue)) {
				throw new ConfigurationException(sprintf(
					'Validator parameter "%s" must be a string or an array of strings, %s given.',
					$listParam, get_debug_type($rawValue)
				));
			} else {
				$parameters[$listParam] = explode(' ', $rawValue);
			}
		}

		if(!isset($parameters['source'])) {
			$parameters['source'] = "parameters";
		}

		$this->setParameters($parameters);

		$name = $this->getParameter('name', Toolkit::uniqid());
		if(!is_string($name)) {
			throw new ConfigurationException('Validator parameter "name" must be a string, ' . get_debug_type($name) . ' given.');
		}
		$this->name = $name;
	}

	/**
	 * Builds a ConfigurationException for a validator parameter whose runtime
	 * value doesn't match the type the validator requires.
	 * @since      1.0.0
	 */
	protected function invalidParameterType(string $paramName, string $expectedType, mixed $value): ConfigurationException
	{
		return new ConfigurationException(sprintf(
			'Validator "%s" parameter "%s" must be %s, %s given.',
			$this->name ?? '?', $paramName, $expectedType, get_debug_type($value)
		));
	}

	/**
	 * Returns the 'source' parameter (the request data holder to validate
	 * against, e.g. "parameters", "files", "headers", "cookies"), narrowed to
	 * string. initialize() always seeds a string default, so a non-string
	 * here means something set the parameter after the fact with the wrong
	 * type.
	 * @since      1.0.0
	 */
	protected function getSourceParameter(): string
	{
		$value = $this->getParameter('source');
		if(!is_string($value)) {
			throw $this->invalidParameterType('source', 'a string', $value);
		}
		return $value;
	}

	/**
	 * Returns the 'required' parameter narrowed to bool.
	 * @since      1.0.0
	 */
	protected function isRequiredParameter(): bool
	{
		$value = $this->getParameter('required', true);
		if(!is_bool($value)) {
			throw $this->invalidParameterType('required', 'a boolean', $value);
		}
		return $value;
	}

	/**
	 * Returns the 'severity' parameter narrowed to string.
	 * @since      1.0.0
	 */
	protected function getSeverityParameter(): string
	{
		$value = $this->getParameter('severity', 'error');
		if(!is_string($value)) {
			throw $this->invalidParameterType('severity', 'a string', $value);
		}
		return $value;
	}

	/**
	 * Returns the 'depends' parameter narrowed to a list of strings.
	 * initialize() always normalizes it to an array; this only re-validates
	 * the element types.
	 * @return     array<int, string>
	 * @since      1.0.0
	 */
	protected function getDependsParameter(): array
	{
		return $this->getStringListParameter('depends');
	}

	/**
	 * Returns the 'provides' parameter narrowed to a list of strings.
	 * @return     array<int, string>
	 * @since      1.0.0
	 */
	protected function getProvidesParameter(): array
	{
		return $this->getStringListParameter('provides');
	}

	/**
	 * @return     array<int, string>
	 */
	private function getStringListParameter(string $paramName): array
	{
		$value = $this->getParameter($paramName, []);
		if(!is_array($value)) {
			throw $this->invalidParameterType($paramName, 'an array', $value);
		}
		foreach($value as $item) {
			if(!is_string($item)) {
				throw $this->invalidParameterType($paramName, 'an array of strings', $item);
			}
		}
		/** @var array<int, string> $value */
		return $value;
	}

	/**
	 * Returns the 'translation_domain' parameter narrowed to string|null.
	 * @since      1.0.0
	 */
	protected function getTranslationDomainParameter(): ?string
	{
		if(!$this->hasParameter('translation_domain')) {
			return null;
		}
		$value = $this->getParameter('translation_domain');
		if($value !== null && !is_string($value)) {
			throw $this->invalidParameterType('translation_domain', 'a string or null', $value);
		}
		return $value;
	}

	/**
	 * Returns the 'base' parameter narrowed to the type VirtualArrayPath's
	 * constructor accepts.
	 * @since      1.0.0
	 */
	protected function getBaseParameter(): string|int|null
	{
		$value = $this->getParameter('base');
		if($value !== null && !is_string($value) && !is_int($value)) {
			throw $this->invalidParameterType('base', 'a string, integer, or null', $value);
		}
		return $value;
	}

	/**
	 * Retrieve the current application context.
	 * @return     Context The current Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		if ($this->context === null) {
			throw new ValidatorException('Validator "' . ($this->name ?? '?') . '" was used before initialize() ran (or after reset() returned it to its pool).');
		}
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
	 * Narrows $curBase to a real VirtualArrayPath, failing loudly instead of
	 * crashing with a null method call. $curBase is only ever null before
	 * setParentContainer() has run, or when the parent container's getBase()
	 * returned something coerceBase() couldn't recognize (see its docblock)
	 * -- both are genuine wiring problems worth surfacing explicitly rather
	 * than propagating a null through the validation path.
	 */
	private function requireCurBase(): VirtualArrayPath
	{
		if ($this->curBase === null) {
			throw new ValidatorException('Validator "' . ($this->name ?? '?') . '" has no base path; setParentContainer() was never called or its parent returned an unresolvable base.');
		}
		return $this->curBase;
	}

	/**
	 * Narrows $validationParameters to the WebRequest execute() supplied,
	 * failing loudly instead of crashing with a null method call. Only null
	 * before execute() has run.
	 */
	private function requireRequest(): WebRequest
	{
		if ($this->validationParameters === null) {
			throw new ValidatorException('Validator "' . ($this->name ?? '?') . '" was used before execute() supplied a request.');
		}
		return $this->validationParameters;
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
		$paramType = $this->getSourceParameter();
		$request = $this->requireRequest();
		$base = $this->requireCurBase();
		// NOTE: Parameters are fetched by value from PSR-7 request; mutation will not write back.
		$array = $request->getParameters($paramType);
		if ($paramName === '' || $paramName === null) {
			// Empty argument: treat the current base path itself as the value (legacy Quiote semantics for <argument></argument> with base="Foo[]")
			$value = $base->getValue($array, null);
		} else {
			$value = $base->getValueByChildPath($paramName, $array);
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
				$stringParts = [];
				foreach ($value as $headerPart) {
					if (!is_string($headerPart)) {
						throw new ValidatorException(sprintf(
							'Validator "%s" received a non-string header value part (%s) for header "%s".',
							$this->name ?? '?', get_debug_type($headerPart), $paramName ?? '?'
						));
					}
					$stringParts[] = $headerPart;
				}
				$value = implode(', ', $stringParts);
			}
		}
		// Fallback: if source==parameters and value is still null, attempt direct runtime lookup
		if ($value === null && $paramName !== null && $paramType === 'parameters') {
			try {
				// getParameters(null) returns runtime overlay + intrinsic; runtime wins.
				$merged = $request->getParameters(null);
				if (array_key_exists($paramName, $merged)) {
					$value = $merged[$paramName];
				}
			} catch (\Throwable $e) {
				// $value keeps whatever the caller resolved, so validation proceeds against the
				// unmerged view of the parameter.
				\Quiote\Logging\Log::for($this)->warning(
					'[Validator] could not merge runtime parameters while reading "' . $paramName . '": '
					. $e->getMessage()
				);
			}
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
			$first = current($argNames);
			return $first === false ? null : $first;
		} else {
			if(isset($this->arguments[$name])) {
				return $this->arguments[$name];
			}
		}

		return null;
	}

	/**
	 * Returns all arguments which should be validated.
	 *
	 * Public (rather than the framework-internal default) so tooling that
	 * introspects a live, already-registered validator tree without running
	 * a real request -- e.g. the MCP package deriving a tool's input schema
	 * from validators registered via the fluent {@see
	 * \Quiote\Validator\Compiler\Runtime\ValidatorBuilder} rather than an
	 * XML file -- can read back which request parameters a validator
	 * targets.
	 * @return     array<int|string, string> A list of input arguments names.
	 * @since      1.0.0
	 */
	public function getArguments()
	{
		return $this->arguments;
	}

	/**
	 * Sets the arguments which should be flagged with the result of the
	 * validator
	 * @param      array<int, string> $arguments A list of (absolute) argument names
	 * @return     void
	 * @since      1.0.0
	 */
	protected function setAffectedArguments(array $arguments)
	{
		$this->affectedArguments = $arguments;
	}

	/**
	 * Returns whether all arguments are set in the validation input parameters.
	 * Set means anything but empty string.
	 * @param      bool $throwError Whether an error should be thrown for each missing
	 *                  argument if this validator is required.
	 * @param      ?array<int, string> $fullArgumentNames Precomputed full path per
	 *                  argument (same order as getArguments()), e.g. already produced
	 *                  by getFullArgumentNames() by the caller -- avoids resolving the
	 *                  same base+argument path twice per validator run. Computed
	 *                  locally when omitted.
	 * @return     bool Whether the arguments are set.
	 * @since      1.0.0
	 */
	protected function checkAllArgumentsSet($throwError = true, ?array $fullArgumentNames = null)
	{
		$isRequired = $this->isRequiredParameter();
		$paramType = $this->getSourceParameter();
		$result = true;
		$request = $this->requireRequest();
		$base = $this->requireCurBase();
		$fullArgumentNames ??= $this->getFullArgumentNames();

		// getFullArgumentNames() builds its list positionally (sequential 0..n-1),
		// independent of getArguments()'s own keys, so we walk both in lockstep
		// by position rather than by the (possibly non-sequential) argument key.
		$i = 0;
		foreach($this->getArguments() as $argument) {
			// Empty argument means current base element when using base paths (e.g. base="User[]" + <argument></argument>)
			$pName = $fullArgumentNames[$i] ?? ($argument === '' ? $base->__toString() : $base->pushRetNew($argument)->__toString());
			$i++;
			$logger = \Quiote\Logging\Log::for($this);
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][debug][checkAllArgumentsSet] validator=' . $this->getName() . ' argumentRaw=' . ($argument===''?'<empty>':$argument) . ' resolvedName=' . $pName); }
			$empty = null;
			if ($argument === '') {
				// Directly inspect current base value out of the parameter tree because isValueEmpty() cannot resolve nested bracket paths for dynamic indices.
				$array = $request->getParameters($paramType);
				$baseValue = $base->getValue($array, null);
				$empty = ($baseValue === null || $baseValue === '' || (is_array($baseValue) && count($baseValue) === 0));
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][debug][checkAllArgumentsSet] emptyArgBaseInspect base=' . $base->__toString() . ' empty=' . ($empty?'1':'0') . ' baseValueType=' . gettype($baseValue)); }
			} else {
				try {
					$empty = $request->isValueEmpty($paramType, $pName);
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
	 * @param      string|array<int, string>|null $affectedArgument The arguments which are affected by this error.
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
				$base = $this->requireCurBase();
				foreach($affectedArguments as &$arg) {
					$arg = $base->pushRetNew($arg)->__toString();
				}
			}
		}

		if($setAffected) {
			$this->affectedArguments = $affectedArguments;
		}

		$error = $this->getErrorMessage($index);

		if($this->hasParameter('translation_domain')) {
			$tm = $this->getContext()->getContainer()->tryGet(\Quiote\Translation\TranslationManager::class);
			if ($tm === null) {
				throw new ConfigurationException('Validator "' . ($this->name ?? '?') . '" specifies a translation_domain but translations are not enabled (core.use_translation).');
			}
			$error = $tm->_($error, $this->getTranslationDomainParameter());
		}

		if(!$this->incident) {
			$this->incident = new ValidationIncident($this, self::mapErrorCode($this->getSeverityParameter()));
		}

		foreach($affectedArguments as &$argument) {
			$argument = new ValidationArgument($argument, $this->getSourceParameter());
		}

		if($error !== null || count($affectedArguments) != 0) {
			// don't throw empty error messages without affected fields
			$this->incident->addError(new ValidationError($error ?? '', $index ?? '', $affectedArguments));
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
	 * @param      ValidationArgument|string|null $argument An optional parameter name which should be used for
	 *                   exporting instead of the "export" attribute value, or an
	 *                   ValidationArgument object if the value should be
	 *                   exported to a different source.
	 * @param      ?int $result The result status code to use for the exported value.
	 *                   Defaults to Validator::SUCCESS.
	 * @return     void
	 * @since      1.0.0
	 */
	protected function export($value, string|ValidationArgument|null $argument = null, ?int $result = null)
	{
		if($argument === null) {
			$configuredArgument = $this->getParameter('export');
			$argument = is_string($configuredArgument) ? $configuredArgument : null;
		}

		if($result === null) {
			$raw = $this->getParameter('export_severity', Validator::SUCCESS);
			if(is_int($raw)) {
				$result = $raw;
			} elseif(is_string($raw) && is_numeric($raw)) {
				$result = (int) $raw;
			} elseif(is_string($raw) && defined($raw)) {
				$const = constant($raw);
				if(!is_int($const)) {
					throw $this->invalidParameterType('export_severity', 'a constant name resolving to an int', $const);
				}
				$result = $const;
			} else {
				throw $this->invalidParameterType('export_severity', 'an int, numeric string, or defined constant name', $raw);
			}
		}

		if(!($argument instanceof ValidationArgument) && (!is_string($argument) || $argument === '')) {
			return;
		}

		if($argument instanceof ValidationArgument) {
			$source = $argument->getSource();
			$name = $argument->getName();
		} else {
			$exportToSource = $this->getParameter('export_to_source');
			if($exportToSource === null) {
				$source = $this->getSourceParameter();
			} elseif(!is_string($exportToSource)) {
				throw $this->invalidParameterType('export_to_source', 'a string', $exportToSource);
			} else {
				$source = $exportToSource;
			}
			$name = $argument;
		}

		$request = $this->requireRequest();
		$base = $this->requireCurBase();
		$array = $request->getParameters($source);
		$currentParts = $base->getParts();

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
			$flatName = $cp->__toString();
			if(!str_contains($flatName, '[')) {
				$request = $request->setParameter($flatName, $value);
				if (($logger = \Quiote\Logging\Log::for($this))->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][export][debug] stored simple name=' . $flatName . ' type=' . (get_debug_type($value))); }
			} elseif(($bracketPos = strpos($flatName, '[')) !== false) {
				// Parse root and indices: e.g. User[0] => root=User, indices=[0]
				$root = substr($flatName, 0, $bracketPos);
				$indicesPart = substr($flatName, strlen($root));
				if($root !== '') {
					$indices = [];
					if(preg_match_all('/\[(.*?)\]/', $indicesPart, $m)) {
						foreach($m[1] as $seg) { $indices[] = $seg; }
					}
					// Build nested array reference in runtime parameters
					$runtime = $request->getParameters('runtime');
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
					$request = $request->setParameter($root, $runtime[$root]);
					// Remember root parameter name so we can register it as succeeded argument
					$rootParameterName = $root;
					if (($logger = \Quiote\Logging\Log::for($this))->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][export][debug] stored bracketed root=' . $root . ' flat=' . $flatName); }
				}
			}
		} catch(\Throwable $e) {
			// $request keeps whatever was written before the failure, so a partially exported
			// value can reach the action while the rest of the export is missing.
			\Quiote\Logging\Log::for($this)->error(
				'[Validator] export failed for validator "' . ($this->getName() ?? '?') . '": '
				. $e->getMessage()
			);
		}
		$this->validationParameters = $request;
		$parentContainer = $this->parentContainer;
		if($parentContainer !== null) {
			// make sure the parameter doesn't get removed by the validation manager
			if(is_array($value)) {
				// for arrays all child elements need to be marked as not processed
				foreach(ArrayPathDefinition::getFlatKeyNames($value) as $keyName) {
					$parentContainer->addArgumentResult(new ValidationArgument($cp->pushRetNew($keyName)->__toString(), $source), $result, $this);
				}
			}
			$parentContainer->addArgumentResult(new ValidationArgument($cp->__toString(), $source), $result, $this);

			// Also register the root parameter (e.g. 'User') as a succeeded argument
			// when we export to bracketed names (e.g. 'User[0]'). This prevents the pruning logic
			// from removing the root array parameter that we just created.
			if($rootParameterName !== null) {
				$parentContainer->addArgumentResult(new ValidationArgument($rootParameterName, $source), $result, $this);
				if (($logger = \Quiote\Logging\Log::for($this))->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug('[Validator][export][debug] registered root argument=' . $rootParameterName . ' to prevent pruning'); }
			}
		}

		// Always-on whitelist: ensure exported parameter key is whitelisted immediately
		try {
			$names = [$cp->__toString()];
			if($rootParameterName) { $names[] = $rootParameterName; }
			$request = $request->enforceValidatedParameters($names);
		} catch(\Throwable $e) {
			// The exported name is not marked validated, so strict access to the value this
			// validator just produced is denied.
			\Quiote\Logging\Log::for($this)->error(
				'[Validator] could not whitelist the exported parameter of validator "'
				. ($this->getName() ?? '?') . '"; it will not be readable: ' . $e->getMessage()
			);
		}
		$this->validationParameters = $request;
	}

	/**
	 * Exports a type-coerced value, defaulting the target to this validator's
	 * own argument name.
	 * Meant for a validator whose whole job is turning the raw input into a
	 * native type (string/number/boolean/decoded JSON): {@see export()} when an
	 * explicit 'export' parameter is configured, otherwise a direct write-back
	 * of $value under this validator's own single argument name, so validating
	 * a field is enough to get its coerced value back without also having to
	 * configure 'export'. Bypasses export()'s bracket-path/array handling and
	 * succeeded-argument bookkeeping, which a same-name scalar write-back does
	 * not need. Does nothing when the validator has more than one argument or
	 * a base-array argument (whose name is empty), since neither has a single
	 * obvious target -- those stay opt-in via an explicit 'export'.
	 * @param      mixed $value The coerced value to write back.
	 * @return     void
	 * @since      4.1.0
	 */
	protected function exportOwnArgumentByDefault($value): void
	{
		if($this->hasParameter('export')) {
			$this->export($value);
			return;
		}

		if($this->hasMultipleArguments()) {
			return;
		}

		$argumentName = $this->getArgument();
		if($argumentName === null || $argumentName === '') {
			return;
		}

		$validationParameters = $this->validationParameters;
		if($validationParameters === null) {
			throw new ValidatorException('Validator "' . ($this->getName() ?? '?') . '" has no request; validate() ran before execute() supplied one.');
		}

		try {
			$this->validationParameters = $validationParameters->setParameter($argumentName, $value);
		} catch(\Throwable $e) {
			// Validation still succeeded; what is lost is the coerced value replacing the
			// submitted input, so the action reads the raw input instead.
			\Quiote\Logging\Log::for($this)->error(
				'[' . static::class . '] could not write back the coerced value for "'
				. $argumentName . '"; the action will read the uncoerced input: '
				. $e->getMessage()
			);
		}
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
		$curBase = $this->requireCurBase();
		$logger = \Quiote\Logging\Log::for($this);
		if($base->length() == 0) {
			// we have an empty base so we do the actual validation
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$argList = $this->getArguments();
				$argExport = [];
				foreach($argList as $a){ $argExport[] = $a === '' ? "<empty>" : $a; }
				$logger->debug('[Validator][debug][pre-validate] name=' . $this->getName() . ' curBase=' . $curBase->__toString() . ' args=' . implode(',', $argExport));
			}
			$dependsTokens = $this->getDependsParameter();
			if($this->getDependencyManager() && (count($dependsTokens) > 0 && !$this->getDependencyManager()->checkDependencies($dependsTokens, $curBase))) {
				// dependencies not met, exit with success
				return self::NOT_PROCESSED;
			}

			$this->affectedArguments = $this->getFullArgumentNames();

			$result = self::SUCCESS;
			$errorCode = self::mapErrorCode($this->getSeverityParameter());

			$allArgsSet = $this->checkAllArgumentsSet(false, $this->affectedArguments);
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
				if($this->isRequiredParameter()) {
					$this->throwError('required');
					$result = $errorCode;
				} else {
					// we don't throw an error here because this is not an incident per se
					// but rather a non validated field
					$result = self::NOT_PROCESSED;
				}
			}

			$parentContainer = $this->parentContainer;
			if($parentContainer !== null) {
				foreach($this->affectedArguments as $fieldname) {
					$parentContainer->addArgumentResult(new ValidationArgument($fieldname, $this->getSourceParameter()), $result, $this);
				}

				if($this->incident) {
					$parentContainer->addIncident($this->incident);
				}
			}

			$this->incident = null;
			// put dependencies provided by this validator into manager
			$providesTokens = $this->getProvidesParameter();
			if($this->getDependencyManager() && $result == self::SUCCESS && count($providesTokens) > 0) {
				$this->getDependencyManager()->addDependTokens($providesTokens, $curBase);
			}
			return $result;

		} elseif($base->left() !== '') {
			/*
			 * the next component in the base is no wildcard so we
			 * just put it into our own base and validate further
			 * into the base.
			 */

			$nextComponent = $base->shift();
			if($nextComponent !== null) {
				$curBase->push((string) $nextComponent);
			}
			$ret = $this->validateInBase($base);
			$curBase->pop();

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
				$dependsTokens = $this->getDependsParameter();
				if($this->getDependencyManager() && (count($dependsTokens) > 0 && !$this->getDependencyManager()->checkDependencies($dependsTokens, $curBase))) {
					// since the dependencies are only ever checked if the base gets empty (which happens when
					// the validation is about to validate an argument), but we are already bailing out in an earlier
					// stage, lets do the dependency check so the validator doesn't accidently return an error even
					// if it's dependencies aren't met
					return self::NOT_PROCESSED;
				} else {
					if($this->isRequiredParameter()) {
						$this->throwError('required');
						return self::mapErrorCode($this->getSeverityParameter());
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
		$source = $this->getSourceParameter();
		if($source != "parameters" && !in_array($source, ["parameters", "files", "headers", "cookies"], true)) {
			throw new ConfigurationException('Unknown source "' . $source . '" specified in validator ' . $this->getName());
		}

		$this->validationParameters = $parameters;
		$base = new VirtualArrayPath($this->getBaseParameter());

		$res = $this->validateInBase($base);
		if($this->incident && $this->parentContainer) {
			$this->parentContainer->addIncident($this->incident);
			$this->incident = null;
		}
		return $res;
	}

	/**
	 * The WebRequest this validator ended execute() with. WebRequest is
	 * immutable, so export()'s setParameter()/enforceValidatedParameters()
	 * calls replace $this->validationParameters with a new instance rather
	 * than mutating it in place — callers (ValidationManager) must fetch the
	 * final instance back out via this accessor after execute() returns.
	 */
	public function getMutatedRequest(): ?WebRequest
	{
		return $this->validationParameters instanceof WebRequest ? $this->validationParameters : null;
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
	 * @return     array<int, int|string> The available keys.
	 * @since      1.0.0
	 */
	protected function getKeysInCurrentBase()
	{
		$paramType = $this->getSourceParameter();
		$base = $this->requireCurBase();

		$array = $this->requireRequest()->getParameters($paramType);
		$names = $base->getValue($array, []);
		$logger = \Quiote\Logging\Log::for($this);
		if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
			$logger->debug('[Validator][debug][getKeysInCurrentBase] base=' . $base->__toString() . ' keys=' . (is_array($names)?implode(',', array_keys($names)):'<non-array>'));
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
		$base = $this->requireCurBase();
		$arguments = [];
		foreach($this->getArguments() as $argument) {
			if($argument) {
				$arguments[] = $base->pushRetNew($argument)->__toString();
			} else {
				$arguments[] = $base->__toString();
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

	/**
	 * Returns the validator to its uninitialized state for reuse across requests.
	 *
	 * Detaches the context and the parent container, and drops the name, base,
	 * arguments, error messages, affected arguments and any incident raised on
	 * the last run, then clears the parameters through the ParameterHolder
	 * base. A reset validator has to go through initialize() again before it
	 * can validate anything.
	 */
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