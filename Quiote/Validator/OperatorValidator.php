<?php
namespace Quiote\Validator;

use Quiote\Exception\ValidatorException;
use Quiote\Request\WebRequest;
use InvalidArgumentException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * OperatorValidator
 * Operators group a couple if validators...
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class OperatorValidator extends Validator implements IValidatorContainer, ResetInterface
{
	#[\Override]
	public static function getAcceptedParameters(): array
	{
		return array_merge(parent::getAcceptedParameters(), ['skip_errors']);
	}

	/**
	 * @var        array<string, Validator> The child validators.
	 */
	protected $children = [];

	/**
	 * @var        int The highest error severity in the container.
	 */
	protected $result = Validator::SUCCESS;

	/**
	 * Per-field results reported by child validators during this group's
	 * validate(), buffered instead of forwarded immediately -- see
	 * addArgumentResult() for why.
	 * @var        array<int, array{argument: ValidationArgument, result: int, validator: ?Validator}>
	 */
	private array $pendingArgumentResults = [];

	/**
	 * Incidents raised by child validators during this group's validate(),
	 * buffered instead of forwarded immediately -- see addIncident().
	 * @var        array<int, ValidationIncident>
	 */
	private array $pendingIncidents = [];


	/**
	 * Method for checking the validity of child validators.
	 * Some operators (XOR and NOT) need a specific quantity of child
	 * validators so they implement an algorithm that checks of the setup
	 * is valid. This method is run first when execute() is invoked and
	 * should throw an exception if the setup is invalid.
	 * @throws     \Quiote\Exception\ValidatorException If the  quantity of child
	 *                                           validators is invalid
	 * @return     void
	 * @since      1.0.0
	 */
	protected function checkValidSetup()
	{
	}

	/**
	 * Shutdown method, for shutting down the model etc.
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
     * Adds a validation result for a given field.
     * @param      Validator $validator The validator.
     * @param      string $fieldname The name of the field which has been validated.
     * @param      int $result The result of the validation.
     * @return     mixed
     * @since      1.0.0
     */
    #[\Deprecated(message: '1.0.0')]
    public function addFieldResult($validator, $fieldname, $result)
	{
		// IValidatorContainer does not declare addFieldResult() (it's a
		// deprecated, legacy method some containers still implement), so
		// this cannot be called through the interface type alone.
		if($this->parentContainer !== null && method_exists($this->parentContainer, 'addFieldResult')) {
			return $this->parentContainer->addFieldResult($validator, $fieldname, $result);
		}
	}

	/**
	 * Adds a intermediate result of an validator for the given argument.
	 *
	 * Buffered rather than forwarded immediately: a child validator reports
	 * its own individual pass/fail here as soon as IT finishes, regardless of
	 * how the group as a whole (and(), or(), xor()...) ultimately resolves.
	 * Forwarding it straight to the parent (as this used to do) meant a
	 * losing sibling inside an otherwise-passing or()/xor() group still
	 * reported the shared field as failed, and ValidationManager's
	 * any-failure-wins pruning then wiped that field's value even though
	 * validation, as a whole, succeeded. execute() flushes this buffer once
	 * the group's own verdict is known, upgrading every buffered result to
	 * SUCCESS when the group passed.
	 * @param      ValidationArgument $argument The argument
	 * @param      int $result The arguments result.
	 * @param      Validator $validator The validator (if the error was caused
	 *                                     inside a validator).
	 * @return     null
	 * @since      1.0.0
	 */
	public function addArgumentResult(ValidationArgument $argument, $result, $validator = null)
	{
		$this->pendingArgumentResults[] = ['argument' => $argument, 'result' => $result, 'validator' => $validator];
		return null;
	}

	/**
	 * Forward this group's buffered per-field child results to the real
	 * parent container, upgrading every one of them to SUCCESS when the
	 * group's own overall result was SUCCESS -- see addArgumentResult().
	 * @param      int $groupResult The group's own overall validation result.
	 */
	private function flushPendingArgumentResults(int $groupResult): void
	{
		$pending = $this->pendingArgumentResults;
		$this->pendingArgumentResults = [];
		if($this->parentContainer === null) {
			return;
		}
		foreach($pending as $entry) {
			$result = $groupResult === Validator::SUCCESS ? Validator::SUCCESS : $entry['result'];
			$this->parentContainer->addArgumentResult($entry['argument'], $result, $entry['validator']);
		}
	}

	/**
	 * Adds an incident to the validation result.
	 *
	 * Buffered for the same reason as addArgumentResult(): a losing child's
	 * incident carries its own affected-argument list, and
	 * ValidationReport::addIncident() records a per-argument failure
	 * severity for every one of them as a side effect -- so forwarding it
	 * immediately would contaminate the shared field's result (and the
	 * report's own overall result) even when the group as a whole passes.
	 * Discarded on flush if the group passed; forwarded unchanged otherwise.
	 * @param      ValidationIncident $incident The incident.
	 * @return     null
	 * @since      1.0.0
	 */
	public function addIncident(ValidationIncident $incident)
	{
		$this->pendingIncidents[] = $incident;
		return null;
	}

	/**
	 * Forward this group's buffered child incidents to the real parent
	 * container, but only when the group's own overall result was a
	 * failure -- see addIncident().
	 * @param      int $groupResult The group's own overall validation result.
	 */
	private function flushPendingIncidents(int $groupResult): void
	{
		$pending = $this->pendingIncidents;
		$this->pendingIncidents = [];
		if($this->parentContainer === null || $groupResult === Validator::SUCCESS) {
			return;
		}
		foreach($pending as $incident) {
			$this->parentContainer->addIncident($incident);
		}
	}

	/**
	 * Adds new child validator.
	 * @param      Validator $validator The new child validator.
	 * @return     void
	 * @since      1.0.0
	 */
	public function addChild(Validator $validator)
	{
		$name = $validator->getName();
		if($name === null) {
			throw new InvalidArgumentException('Cannot add a validator with no name (was it reset without being re-initialized?)');
		}
		if(isset($this->children[$name])) {
			throw new InvalidArgumentException('A validator with the name "' . $name . '" already exists');
		}

		$this->children[$name] = $validator;
		$validator->setParentContainer($this);
	}

	/**
	 * Returns a named child validator.
	 * @param      string $name The name of the child validator.
	 * @return     Validator The named child validator.
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
	 * @return     array<string, Validator> An array of Validator instances.
	 * @since      1.0.0
	 */
	public function getChilds()
	{
		return $this->children;
	}

	/**
	 * Registers an array of validators.
	 * @param      array<Validator> $validators The array of validators.
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
	 * Gets parent's dependency manager.
	 * @return     DependencyManager The parent's dependency manager.
	 * @since      1.0.0
	 */
    #[\Override]
    public function getDependencyManager()
	{
		$parentContainer = $this->parentContainer;
		if ($parentContainer === null) {
			throw new ValidatorException('Operator validator "' . ($this->getName() ?? '?') . '" has no parent container; it was never added as a child (or was reset without being re-attached).');
		}
		return $parentContainer->getDependencyManager();
	}

	/**
	 * Narrows $validationParameters (inherited from Validator, and typed
	 * nullable there because it's only populated once execute() runs) to a
	 * concrete WebRequest for dispatching to child validators. Only ever
	 * null before this operator's own execute() has run, which validate()
	 * always happens after.
	 * @return     WebRequest The request supplied to this operator's execute().
	 * @since      1.0.0
	 */
	protected function requireValidationParameters(): WebRequest
	{
		if ($this->validationParameters === null) {
			throw new ValidatorException('Operator validator "' . ($this->getName() ?? '?') . '" has no request; validate() ran before execute() supplied one.');
		}
		return $this->validationParameters;
	}

	/**
	 * Returns the result from the error manager.
	 * @return     int The result of the validation process.
	 * @since      1.0.0
	 */
	public function getResult()
	{
		return $this->result;
	}

	/**
	 * Executes the validator.
	 * Executes the operators validate()-Method after checking the quantity
	 * of child validators with checkValidSetup().
	 * @param      WebRequest $parameters The parameters which should be validated.
	 * @return     int The result of validation (SUCCESS, NONE, NOTICE, ERROR, CRITICAL).
	 * @since      1.0.0
	 */
    #[\Override]
    public function execute(WebRequest $parameters)
	{
		// check if we have a valid setup of validators
		$this->checkValidSetup();
		
		$result = parent::execute($parameters);
		if($result != Validator::SUCCESS && !$this->getParameter('skip_errors') && $this->result == Validator::CRITICAL) {
			/*
			 * one of the child validators resulted with CRITICAL
			 * we change our operator's result to CRITICAL, too so the
			 * surrounding validator container is aware of the critical
			 * result and can abort further validation...
			 */
			$result = Validator::CRITICAL;
		}

		$this->flushPendingArgumentResults($result);
		$this->flushPendingIncidents($result);

		return $result;
	}

	#[\Override]
    public function reset() : void
	{
		foreach($this->children as $child) {
			$child->reset();
		}
		$this->children = [];
		$this->result = Validator::SUCCESS;
		$this->pendingArgumentResults = [];
		$this->pendingIncidents = [];
	}
}