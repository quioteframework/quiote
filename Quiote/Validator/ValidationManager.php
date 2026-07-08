<?php
namespace Quiote\Validator;

use Quiote\Context;
use Quiote\Exception\ConfigurationException;
use Quiote\Request\WebRequest;
use Quiote\Util\ArrayPathDefinition;
use Quiote\Util\ParameterHolder;
use Quiote\Util\VirtualArrayPath;
use InvalidArgumentException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * ValidationManager provides management for request parameters and their
 * associated validators.
 * @since      1.0.0
 * @version    1.0.0
 */
class ValidationManager extends ParameterHolder implements IValidatorContainer, ResetInterface
{
	/**
	 * @var        DependencyManager The dependency manager.
	 */
	protected $dependencyManager = null;

	/**
	 * @var        array<string,Validator> An array of child validators.
	 */
	protected $children = [];

	/**
	 * @var        Context The context instance.
	 */
	protected $context = null;

	/**
	 * @var        ValidationReport The report container storing the validation results.
	 *                              Set eagerly in initialize()/clear()/reset() -- like
	 *                              $context above, this is never read before initialize()
	 *                              has run (Context::initialize() calls it immediately
	 *                              after constructing a factory-managed service).
	 */
	protected $report = null;

	/**
	 * @var array<string,mixed> Raw as-submitted request parameters, captured
	 * at the top of execute() before any pruning happens. Deliberately NOT
	 * reachable via WebRequest::getParameter()/getParameters() -- a value
	 * that fails even one of several validators registered against the same
	 * name is scrubbed from the request entirely (see
	 * WebRequest::pruneParametersToValidated()), which is correct for
	 * business logic but breaks redisplaying the submitted value in an HTML
	 * form after a validation failure. This snapshot exists ONLY for that
	 * redisplay use case (see FormPopulationEngine) and must never be used
	 * for anything else -- it is exactly the raw, unvalidated input strict
	 * mode exists to keep out of application code.
	 */
	private array $rawParameterSnapshot = [];

	/**
	 * All request variables are always available.
	 */
	const MODE_RELAXED = 'relaxed';

	/**
	 * All request variables are available when no validation defined else only 
	 * validated request variables are available.
	 */
	const MODE_CONDITIONAL = 'conditional';

	/**
	 * Only validated request variables are available.
	 */
	const MODE_STRICT = 'strict';

	/**
	 * initializes the validator manager.
	 * @param      Context $context The context instance.
	 * @param      array<string,mixed> $parameters The initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		if(isset($parameters['mode'])) {
			// MODE_RELAXED is no longer accepted — it silently disables parameter
			// whitelisting which leads to unvalidated parameter access exceptions
			// when actions read exported validator parameters.
			if($parameters['mode'] === self::MODE_RELAXED) {
				$parameters['mode'] = self::MODE_STRICT;
			}
			if(!in_array($parameters['mode'], [self::MODE_CONDITIONAL, self::MODE_STRICT])) {
				$modeValue = $parameters['mode'];
				$modeLabel = is_scalar($modeValue) ? (string) $modeValue : get_debug_type($modeValue);
				throw new ConfigurationException('Invalid validation mode "' . $modeLabel . '" specified');
			}
		} else {
			$parameters['mode'] = self::MODE_STRICT;
		}

		$this->context = $context;
		$this->setParameters($parameters);

		$this->dependencyManager = new DependencyManager();
		$this->report = new ValidationReport();
		$this->children = [];
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
	 * Retrieve the validation result report container of the last validation run.
	 * @return     ValidationReport The result report container.
	 * @since      1.0.0
	 */
	public function getReport()
	{
		return $this->report;
	}

	/**
	 * Creates a new validator instance.
	 * @template  T of Validator
	 * @param      class-string<T> $class The name of the class implementing the validator.
	 * @param      array<int|string,mixed> $arguments The argument names.
	 * @param      array<string,string> $errors The error messages.
	 * @param      array<string,mixed> $parameters The validator parameters.
	 * @param      ?IValidatorContainer $parent The parent (will use the validation
	 *                                      manager if null is given)
	 * @return     T
	 * @since      1.0.0
	 */
	public function createValidator(string $class, array $arguments, array $errors = [], array $parameters = [], ?IValidatorContainer $parent = null)
	{
		if($parent === null) {
			$parent = $this;
		}
		$obj = new $class();
		$obj->initialize($this->getContext(), $parameters, $arguments, $errors);
		$parent->addChild($obj);

		return $obj;
	}

	/**
	 * Clears the validation manager for reuse
	 * clears the validator manager by resetting the dependency and error
	 * manager and removing all validators after calling their shutdown
	 * method so they can do a save shutdown.
	 * @return     void
	 * @since      1.0.0
	 */
	public function clear()
	{
		$this->dependencyManager->clear();

		$this->report = new ValidationReport();

		foreach($this->children as $child) {
			$child->shutdown();
		}
		$this->children = [];
	}

	/**
	 * Adds a new child validator.
	 * @param      Validator $validator The new child validator.
	 * @since      1.0.0
	 */
	public function addChild(Validator $validator)
	{
		$name = $validator->getName();
		if($name === null) {
			throw new InvalidArgumentException('Cannot add a validator with no name (was it reset without being re-initialized?)');
		}
		if(isset($this->children[$name])) {
			// In testing environment, allow overwriting validators to prevent conflicts
			if (defined('QUIOTE_TESTING') || (isset($_ENV['QUIOTE_TESTING']) && $_ENV['QUIOTE_TESTING'])) {
				// Remove the existing validator first
				unset($this->children[$name]);
			} else {
				throw new InvalidArgumentException('A validator with the name "' . $name . '" already exists');
			}
		}

		$this->children[$name] = $validator;
		$validator->setParentContainer($this);
	}

	/**
	 * Returns a named child validator.
	 * @param      string $name The name of the child validator.
	 * @since      1.0.0
	 */
	public function getChild($name)
	{
		if(!isset($this->children[$name])) {
			throw new InvalidArgumentException('A validator with the name "' . $name . '" does not exist');
		}

		return $this->children[$name];
	}

	/**
	 * Returns all child validators.
	 * @return     array<string,Validator> An array of Validator instances.
	 * @since      1.0.0
	 */
	public function getChilds()
	{
		return $this->children;
	}

	/**
	 * Returns the dependency manager.
	 * @return     DependencyManager The dependency manager instance.
	 * @since      1.0.0
	 */
	public function getDependencyManager()
	{
		return $this->dependencyManager;
	}

	/**
	 * Gets the base path of the validator.
	 * @return     \Quiote\Util\VirtualArrayPath The base path.
	 * @since      1.0.0
	 */
	public function getBase()
	{
		$base = $this->getParameter('base', '');
		return new VirtualArrayPath(is_string($base) || is_int($base) ? $base : '');
	}

	/**
	 * Framework-internal escape hatch: the raw, unvalidated parameters as
	 * submitted, captured before any pruning by execute(). NOT reachable via
	 * WebRequest::getParameter()/getParameters() -- this exists solely so
	 * FormPopulationEngine can redisplay a submitted value in an HTML form
	 * after a validation failure scrubbed it from the request (see the class
	 * docblock on $rawParameterSnapshot). Never use this for business logic.
	 * @return array<string,mixed>
	 */
	public function getRawParameterSnapshot(): array
	{
		return $this->rawParameterSnapshot;
	}

	/**
	 * Starts the validation process.
	 * @param      WebRequest $request The data which should be validated.
	 * @return     bool true, if validation succeeded.
	 * @since      1.0.0
	 */
	public function execute(WebRequest $request): bool
	{
		$logger = \Quiote\Logging\Log::for($this);
		$vd = $logger->isEnabled(\Quiote\Logging\Level::Debug);

		// Capture the raw, as-submitted parameters before anything below can
		// prune them. getQueryParams()/getParsedBody() are plain PSR-7
		// accessors, not gated by Quiote's strict-validation whitelist, so
		// this is genuinely raw input -- see getRawParameterSnapshot().
		$this->rawParameterSnapshot = (array)$request->getParsedBody() + $request->getQueryParams();

		// Pre-populate request validated parameters whitelist with the union of all validator argument names.
		// This allows validators themselves to read the raw input for their declared arguments under always-on enforcement.
		{
			$allArgumentNames = [];
			$allExportNames = [];

			// Helper function to recursively collect arguments from validators and their children
			$collectArguments = function(Validator $validator) use (&$collectArguments, &$allArgumentNames, &$allExportNames): void {
				// Collect arguments from this validator
				try {
					$ref = new \ReflectionObject($validator);
					if($ref->hasProperty('arguments')) {
						$prop = $ref->getProperty('arguments');
						// $prop->setAccessible(true); // Deprecated, not needed in PHP 8.1+
						$args = (array)$prop->getValue($validator);
						// Determine declared base for the validator (if any)
						$basePath = '';
						try {
							if($validator->hasParameter('base')) {
								$rawBase = $validator->getParameter('base');
								$basePath = is_scalar($rawBase) ? (string) $rawBase : '';
							}
						} catch(\Throwable) { }
						foreach($args as $arg) {
							if(!is_string($arg)) {
								continue;
							}
							$argName = (string)$arg;
							if($argName !== '') {
								$allArgumentNames[$argName] = true;
								if($basePath !== '') {
									try {
										$base = new VirtualArrayPath($basePath);
										$fullPath = $base->pushRetNew($argName)->__toString();
										if($fullPath !== '') {
											$allArgumentNames[$fullPath] = true;
										}
									} catch(\Throwable) { }
								}
							} else {
								if($basePath !== '') {
									$allArgumentNames[$basePath] = true;
								}
							}
						}
					}
				} catch(\Throwable) { }
				
				// Also include explicit export target if configured
				try {
					if($validator->hasParameter('export')) {
						$exp = $validator->getParameter('export');
						if(is_string($exp) && $exp !== '') { $allArgumentNames[$exp] = true; $allExportNames[$exp] = true; }
					}
				} catch(\Throwable) { }
				
				// Recursively collect from child validators (for OR, AND, etc.)
				try {
					$ref = new \ReflectionObject($validator);
					if($ref->hasProperty('children')) {
						$prop = $ref->getProperty('children');
						// $prop->setAccessible(true); // Deprecated, not needed in PHP 8.1+
						$children = $prop->getValue($validator);
						if(is_array($children)) {
							foreach($children as $child) {
								if($child instanceof Validator) {
									$collectArguments($child);
								}
							}
						}
					}
				} catch(\Throwable) { }
			};
			
			foreach($this->children as $validator) {
				$collectArguments($validator);
			}
			
			if($allArgumentNames) { $request = $request->enforceValidatedParameters(array_keys($allArgumentNames)); }
			// Persist export names list for later re-whitelisting post-prune (if validation fails, no export() call happens)
			$this->setParameter('_predeclared_exports', array_keys($allExportNames));
		}

		$success = true;
		$this->report = new ValidationReport();
		$result = Validator::SUCCESS;

		$executedValidators = 0;
		foreach($this->children as $validator) {
			++$executedValidators;

			$validatorResult = $validator->execute($request);
			// Validator::export() may have replaced its own copy of $request (setParameter/
			// enforceValidatedParameters are immutable); pick up whatever it ended with.
			$request = $validator->getMutatedRequest() ?? $request;
			if ($vd) {
				$logger->debug('[ValidationManager] Result from ' . $validator->getName() . ': ' . $validatorResult);
			}
			$result = max($result, $validatorResult);

			switch($validatorResult) {
				case Validator::SUCCESS:
					continue 2;
				case Validator::INFO:
					continue 2;
				case Validator::SILENT:
					continue 2;
				case Validator::NOTICE:
					continue 2;
				case Validator::ERROR:
					$success = false;
					continue 2;
				case Validator::CRITICAL:
					$success = false;
					break 2;
			}
		}
		$this->report->setResult($result);
		$this->report->setDependTokens($this->getDependencyManager()->getDependTokens());

		$maRaw = $request->getAttribute('module_accessor');
		$aaRaw = $request->getAttribute('action_accessor');
		$ma = is_string($maRaw) ? $maRaw : null;
		$aa = is_string($aaRaw) ? $aaRaw : null;
		$umap = $request->getAttribute('use_module_action_parameters');

		$mode = $this->getParameter('mode');

		if($executedValidators == 0 && $mode == self::MODE_STRICT) {
			// strict mode and no validators executed -> clear the parameters
			if($umap) {
				$maParam = $ma !== null ? $request->getAttribute($ma) : null;
				$aaParam = $aa !== null ? $request->getParameter($aa) : null;
			}
			$request = $request->clearParameters();
			if($umap) {
				if($maParam && $ma !== null) {
					$request = $request->withAttribute($ma, $maParam);
				}
				if($aaParam && $aa !== null) {
					$request = $request->withAttribute($aa, $aaParam);
				}
			}
		}

		// Collect keep/failed sets per source (parameters, headers, cookies, files).
		// Computed unconditionally (empty when $executedValidators === 0) so headers
		// get pruned to nothing below even when no validator ran at all -- headers
		// are just as attacker-controlled as query/body parameters (Content-Type,
		// Authorization, X-Forwarded-*, etc.), so "no validator ran for this
		// action" must mean "no header is readable in execute*()", the same deny-
		// by-default guarantee params already get. Unlike the params branch above,
		// this is NOT gated on $mode: header pruning has always run whenever ANY
		// validator executed (see the pruneExtendedSources() call below),
		// regardless of relaxed/conditional/strict mode, so extending it to the
		// zero-validator case keeps that existing behavior consistent rather than
		// carving out a new mode-based exception.
		$failedArguments = $this->report->getFailedArguments();
		$succeededArguments = $this->report->getSucceededArguments();
		$keepNames = [];
		$failedNames = [];
		foreach($succeededArguments as $argument) {
			$src = $argument->getSource();
			if(!isset($keepNames[$src])) { $keepNames[$src] = []; }
			$name = $argument->getName();
			if($src === 'headers') { $name = strtolower((string) $name); }
			$keepNames[$src][$name] = true;
		}
		foreach($failedArguments as $argument) {
			$src = $argument->getSource();
			if(!isset($failedNames[$src])) { $failedNames[$src] = []; }
			$name = $argument->getName();
			if($src === 'headers') { $name = strtolower((string) $name); }
			$failedNames[$src][$name] = true;
		}
		// Prune headers/cookies/files unconditionally (deny-by-default: every
		// entry not explicitly kept by a validator targeting that source is
		// removed). Params are handled separately below, only when at least one
		// validator ran, since their pruning also depends on $umap/$ma/$aa.
		$request = $request->pruneExtendedSources(
			$keepNames['headers'] ?? [],
			$failedNames['headers'] ?? [],
			$keepNames['cookies'] ?? [],
			$failedNames['cookies'] ?? [],
			$keepNames['files'] ?? [],
			$failedNames['files'] ?? []
		);
		$this->getContext()->setRequest($request);

		if($executedValidators > 0) {
			// Capture already-whitelisted runtime parameter keys before pruning -- i.e. real
			// trusted exports (setParameter()), not values merely staged for a validator to see
			// (setUnvalidatedParameter(), e.g. a promoted route param nobody actually validated).
			// Using getRuntimeParameterKeys() here would re-whitelist every staged-but-unvalidated
			// key too, defeating the point of staging it unvalidated in the first place.
			$preValidationRuntimeKeys = $request->getValidatedRuntimeParameterKeys();

			// Delegate actual pruning to the request implementation (so it can update both
			// intrinsic PSR-7 query/body params and runtime parameters consistently).
			{
				// Flatten only parameter names for legacy method signature compatibility (will accept arrays soon if extended)
				$paramKeeps = array_keys($keepNames['parameters'] ?? []);
				$paramFails = array_keys($failedNames['parameters'] ?? []);
				// CRITICAL: Capture returned request since PSR-7 requests are immutable
				$request = $request->pruneParametersToValidated(
					$paramKeeps,
					$paramFails,
					(bool)$umap,
					$ma,
					$aa
				);

				// Update context with the pruned request so actions get the clean version
				$this->getContext()->setRequest($request);
			}
			// After pruning, merge whitelist with all succeeded parameter names and any exported roots
			{
				$finalWhitelist = array_keys($keepNames['parameters'] ?? []);
				// include predeclared export names even if validation failed (they may be accessed to assert null)
				$predeclaredExportsRaw = $this->getParameter('_predeclared_exports', []);
				$predeclaredExports = is_array($predeclaredExportsRaw) ? $predeclaredExportsRaw : [];
				foreach($predeclaredExports as $exp) {
					if(is_string($exp)) { $finalWhitelist[] = $exp; }
				}
				// include all runtime parameter keys captured before pruning — these were set
				// by validator exports via setParameter() and must remain accessible to actions
				foreach($preValidationRuntimeKeys as $rk) { $finalWhitelist[] = $rk; }
				// include module/action if present
				if($umap) {
					if($ma) { $finalWhitelist[] = $ma; }
					if($aa) { $finalWhitelist[] = $aa; }
				}
				$finalWhitelist = array_values(array_unique($finalWhitelist));
				if($finalWhitelist) { $request = $request->enforceValidatedParameters($finalWhitelist); }
			}
		}

		// Ensure the context always reflects the final request state, regardless of which
		// branch above mutated it (executedValidators==0 clears params without an explicit
		// setRequest() call above; the executedValidators>0 branch already re-syncs mid-flow).
		$this->getContext()->setRequest($request);

		if ($vd) {
			$logger->debug('[ValidationManager] finalSuccess=' . ($success? '1':'0') . ' highestResult=' . $result . ' executedValidators=' . $executedValidators);
		}
		// Also emit a short, unconditional trace of the final outcome and severity when validation fails
		/*if (!$success) {
			try {
				$sev = $this->report?->getResult();
				$names = [];
				foreach ($this->children as $c) { $names[] = method_exists($c, 'getName') ? $c->getName() : 'unknown'; }
				$logger->debug('[ValidationManager] FAIL sev=' . (is_null($sev) ? 'null' : $sev) . ' validators=' . implode(',', $names));
			} catch (\Throwable) {}
		}*/
		return $success;
	}

	/**
	 * Shuts the validation system down.
	 * @return     void
	 * @since      1.0.0
	 */
	public function shutdown()
	{
		foreach($this->children as $child) {
			$child->shutdown();
		}
	}

	/**
	 * Registers multiple validators.
	 * @param      array<int,Validator> $validators An array of validators.
	 * @return     void
	 * @since      1.0.0
	 */
	public function registerValidators(array $validators)
	{
		foreach($validators as $validator) {
			$this->addChild($validator);
		}
	}
	
	/**
	 * Adds an incident to the validation result. This will automatically adjust
	 * the field result table (which is required because one can still manually
	 * add errors either via Request::addError or by directly using this 
	 * method)
	 * @param      ValidationIncident $incident The incident.
	 * @return     void
	 * @since      1.0.0
	 */
	public function addIncident(ValidationIncident $incident)
	{
		$this->report->addIncident($incident);
	}
	
	
	/////////////////////////////////////////////////////////////////////////////
    ////////////////////////////// Deprecated Parts /////////////////////////////
    /////////////////////////////////////////////////////////////////////////////
    /**
     * Returns the final validation result.
     * @return     int The result of the validation process.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getResult()
	{
		$result = $this->report->getResult();
		
		if(null === $result) {
			$result = Validator::NOT_PROCESSED;
		}
		
		return $result;
	}

	/**
     * Adds a validation result for a given field.
     * @param      Validator $validator The validator.
     * @param      string $fieldname The name of the field which has been validated.
     * @param      int $result The result of the validation.
     * @return     void
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function addFieldResult($validator, $fieldname, $result)
	{
		$argument = new ValidationArgument($fieldname);
		$this->report->addArgumentResult($argument, $result, $validator);
	}

	/**
	 * Adds a intermediate result of an validator for the given argument
	 * @param      ValidationArgument $argument The argument
	 * @param      int $result The arguments result.
	 * @param      Validator $validator The validator (if the error was caused
	 *                                     inside a validator).
	 * @return     void
	 * @since      1.0.0
	 */
	public function addArgumentResult(ValidationArgument $argument, $result, $validator = null)
	{
		$this->report->addArgumentResult($argument, $result, $validator);
	}

	/**
     * Will return the highest error code for a field. This can be optionally
     * limited to the highest error code of an validator. If the field was not
     * "touched" by a validator null is returned.
     * @param      string $fieldname The name of the field.
     * @param      string $validatorName The Validator name
     * @return     ?int The error code, or null if the field was never touched by a validator.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getFieldErrorCode($fieldname, $validatorName = null)
	{
		return $this->report->getAuthoritativeArgumentSeverity(new ValidationArgument($fieldname), $validatorName);
	}

	/**
     * Checks whether a field has failed in any validator.
     * @param      string $fieldname The name of the field.
     * @return     bool Whether the field has failed.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function isFieldFailed($fieldname)
	{
		return $this->report->isArgumentFailed(new ValidationArgument($fieldname));
	}

	/**
     * Checks whether a field has been processed by a validator (this includes
     * fields which were skipped because their value was not set and the validator
     * was not required)
     * @param      string $fieldname The name of the field.
     * @return     bool Whether the field was validated.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function isFieldValidated($fieldname)
	{
		return $this->report->isArgumentValidated(new ValidationArgument($fieldname));
	}

	/**
     * Returns all fields which succeeded in the validation. Includes fields which
     * were not processed (happens when the field is "not set" and the validator
     * is not required)
     * @param      string $source The source for which the fields should be returned.
     * @return     array<int,string> An array of field names.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getSucceededFields($source)
	{
		$names = [];
		$arguments = $this->report->getSucceededArguments($source);
		foreach($arguments as $argument) {
			$names[] = $argument->getName();
		}
		
		return $names;
	}
	
	/**
     * Checks if any incidents occurred Returns all fields which succeeded in the
     * validation. Includes fields which were not processed (happens when the
     * field is "not set" and the validator is not required)
     * @param      int $minSeverity The minimum severity which shall be checked for.
     * @return     bool The result.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function hasIncidents($minSeverity = null)
	{
		return count($this->getIncidents($minSeverity)) > 0;
	}

	/**
     * Returns all incidents which happened during the execution of the validation.
     * @param      int $minSeverity The minimum severity a returned incident needs to have.
     * @return     array<int,ValidationIncident> The incidents.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getIncidents($minSeverity = null)
	{
		$incidents = [];
		if($minSeverity === null) {
			return $this->report->getIncidents();
		} else {
			foreach($this->report->getIncidents() as $incident) {
				if($incident->getSeverity() >= $minSeverity) {
					$incidents[] = $incident;
				}
			}
		}
		return $incidents;
	}

	/**
     * Returns all incidents of a given validator.
     * @param      string $validatorName The name of the validator.
     * @param      int $minSeverity The minimum severity a returned incident needs to have.
     * @return     array<int,ValidationIncident> The incidents.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getValidatorIncidents($validatorName, $minSeverity = null)
	{
		$incidents = $this->report->byValidator($validatorName)->getIncidents();
		
		if($minSeverity === null) {
			return $incidents;
		} else {
			$matchingIncidents = [];
			foreach($incidents as $incident) {
				if($incident->getSeverity() >= $minSeverity) {
					$matchingIncidents[] = $incident;
				}
			}
			return $matchingIncidents;
		}
	}
	/**
     * Returns all incidents of a given field.
     * @param      string $fieldname The name of the field.
     * @param      int $minSeverity The minimum severity a returned incident needs to have.
     * @return     array<int,ValidationIncident> The incidents.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getFieldIncidents($fieldname, $minSeverity = null)
	{
		$incidents = $this->report->byArgument($fieldname)->getIncidents();
		
		if($minSeverity === null) {
			return $incidents;
		} else {
			$matchingIncidents = [];
			foreach($incidents as $incident) {
				if($incident->getSeverity() >= $minSeverity) {
					$matchingIncidents[] = $incident;
				}
			}
			return $matchingIncidents;
		}
	}

	/**
     * Returns all errors of a given field.
     * @param      string $fieldname The name of the field.
     * @param      int $minSeverity The minimum severity a returned incident of the error
     *                 needs to have.
     * @return     array<int,ValidationError> The errors.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getFieldErrors($fieldname, $minSeverity = null)
	{
		$incidents = $this->getFieldIncidents($fieldname, $minSeverity);
		$errors = [];
		foreach($incidents as $incident) {
			$errors = array_merge($errors, $incident->getErrors());
		}
		return $errors;
	}

	/**
     * Returns all errors of a given field in a given validator.
     * @param      string $validatorName The name of the validator.
     * @param      string $fieldname The name of the field.
     * @param      ?int $minSeverity The minimum severity a returned incident of the error
     *                 needs to have.
     * @return     array<int,ValidationIncident> The incidents.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getValidatorFieldErrors($validatorName, $fieldname, $minSeverity = null)
	{
		$incidents = $this->getFieldIncidents($fieldname, $minSeverity);
		$matchingIncidents = [];
		foreach($incidents as $incident) {
			$validator = $incident->getValidator();
			if($validator && $validator->getName() == $validatorName) {
				$matchingIncidents[] = $incident;
			}
		}
		return $matchingIncidents;
	}

	/**
     * Returns all failed fields (this are all fields including those with
     * severity none and notice).
     * @return     array<int,string> The names of the fields.
     * @param      int $minSeverity The minimum severity a field needs to have.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getFailedFields($minSeverity = null)
	{
		$fields = [];
		foreach($this->getIncidents($minSeverity) as $incident) {
			$fields = array_merge($fields, $incident->getFields());
		}
		
		return array_values(array_unique($fields));
	}
	
	/**
     * Retrieve an error message.
     * @param      string $name An error name.
     * @return     ?string The error message, or null if there is no such error.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getError($name)
	{
		$incidents = $this->getFieldIncidents($name, Validator::NOTICE);

		if(count($incidents) == 0) {
			return null;
		}

		$errors = $incidents[0]->getErrors();
		return $errors[0]->getMessage();
	}

	/**
     * Retrieve an array of error names.
     * @return     array<int,string> An indexed array of error names.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getErrorNames()
	{
		return $this->getFailedFields();
	}

	/**
     * Retrieve an array of errors.
     * @param      string $name An optional error name.
     * @return     array<string,mixed>|null An associative array of errors(if no name was given) as
     *                   an array with the error messages (key 'messages') and
     *                   the validators (key 'validators') which failed.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getErrors($name = null)
	{
		$errors = [];
		foreach($this->getIncidents(Validator::NOTICE) as $incident) {
			$validator = $incident->getValidator();
			foreach($incident->getErrors() as $error) {
				$msg = $error->getMessage();
				foreach($error->getFields() as $field) {
					if(!isset($errors[$field])) {
						$errors[$field] = ['messages' => [], 'validators' => []];
					}
					$errors[$field]['messages'][] = $msg;
					if($validator) {
						$errors[$field]['validators'][] = $validator->getName();
					}
				}
			}
		}

		if($name === null) {
			return $errors;
		} else {
			return $errors[$name] ?? null;
		}
	}

	/**
     * Retrieve an array of error Messages.
     * @param      string $name An optional error name.
     * @return     array<int,mixed> An indexed array of error messages (if a name was given)
     *                   or an indexed array in this format:
     *                   array('message' => string, 'errors' => array(string))
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function getErrorMessages($name = null)
	{

		if($name !== null) {
			$incidents = $this->getFieldIncidents($name, Validator::NOTICE);
			$msgs = [];
			foreach($incidents as $incident) {
				foreach($incident->getErrors() as $error) {
					$msgs[] = $error->getMessage();
				}
			}
			return $msgs;
		} else {
			$incidents = $this->getIncidents(Validator::NOTICE);
			$msgs = [];
			foreach($incidents as $incident) {
				foreach($incident->getErrors() as $error) {
					$msgs[] = ['message' => $error->getMessage(), 'errors' => $error->getFields()];
				}
			}
			return $msgs;
		}
	}

	/**
     * Indicates whether or not a field has an error.
     * @param      string $name A field name.
     * @return     bool true, if the field has an error, false otherwise.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function hasError($name)
	{
		$ec = $this->getFieldErrorCode($name);
		// greater than or equal to notice cause that's when we need to show an error (this is different to hasErrors() behavior due to legacy)
		return ($ec >= Validator::NOTICE);
	}

	/**
     * Indicates whether or not any errors exist.
     * @return     bool true, if any error exist, otherwise false.
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function hasErrors()
	{
		// anything above notice. just notice means validation didn't fail, although a notice is considered an error itself. but notices only "show up" if other validators with higher severity (error, fatal) failed
		return $this->getResult() > Validator::NOTICE;
	}

	/**
     * Set an error.
     * @param      string $name An error name.
     * @param      string $message An error message.
     * @return     void
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function setError($name, $message)
	{
		$name = new ValidationArgument($name);
		$incident = new ValidationIncident(null, Validator::ERROR);
		$incident->addError(new ValidationError($message, '', [$name]));
		$this->addIncident($incident);
	}

	/**
     * Set an array of errors
     * If an existing error name matches any of the keys in the supplied
     * array, the associated message will be appended to the messages array.
     * @param      array<string,string> $errors An associative array of errors and their associated
     *                   messages.
     * @return     void
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function setErrors(array $errors)
	{
		$incident = new ValidationIncident(null, Validator::ERROR);
		foreach($errors as $name => $error) {
			$name = new ValidationArgument($name);
			$incident->addError(new ValidationError($error, '', [$name]));
		}

		$this->addIncident($incident);
	}

	#[\Override]
    public function reset(): void {
		// Properly shutdown existing validators
		foreach($this->children as $child) {
			$child->reset();
		}
		
		// Clear children array for fresh registration
		$this->children = [];
		
		// Reset dependency manager
		$this->dependencyManager->reset();
		
		// Reset validation report
		$this->report = new ValidationReport();
	}
}
?>
