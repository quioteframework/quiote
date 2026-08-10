<?php
namespace Quiote\Validator;

use Symfony\Contracts\Service\ResetInterface;

/**
 * ValidationError stores an error message and the fields of an error.
 * @since      1.0.0
 * @version    1.0.0
 */
class ValidationError implements ResetInterface
{
	/**
	 * @var        array<string, ValidationArgument> The fields this error affects.
	 */
	protected $arguments = [];

	/**
	 * @var        ?ValidationIncident The incident in which this error
	 *                                     occurred.
	 */
	protected $incident = null;

	/**
     * Constructor
     * @param      string $message The message of this error.
     * @param      string $name The name of the message.
     * @param      array<int, ValidationArgument|string> $arguments The arguments affected by this error.
     * @since      1.0.0
     */
    public function __construct(/**
     * @var        string The message for this error.
     */
    protected $message, /**
     * @var        string The name of the message.
     */
    protected $name, array $arguments)
	{
		foreach($arguments as $argument) {
			if(!($argument instanceof ValidationArgument)) {
				$argument = new ValidationArgument($argument);
			}
			$this->arguments[$argument->getHash()] = $argument;
		}
	}

	/**
	 * Sets the name of this error.
	 * @param      string $name The error name.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setName($name)
	{
		$this->name = $name;
	}

	/**
	 * Retrieves the name of this error.
	 * @return     string The error name.
	 * @since      1.0.0
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Sets the message of this error.
	 * @param      string $message The message.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setMessage($message)
	{
		$this->message = $message;
	}

	/**
	 * Retrieves the message of this error.
	 * @return     string The message.
	 * @since      1.0.0
	 */
	public function getMessage()
	{
		return $this->message;
	}

	/**
	 * Sets the incident which caused this error.
	 * @param      ValidationIncident $incident The incident.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setIncident(ValidationIncident $incident)
	{
		$this->incident = $incident;
	}

	/**
	 * Retrieves the incident which caused this error.
	 * @return     ?ValidationIncident The incident.
	 * @since      1.0.0
	 */
	public function getIncident()
	{
		return $this->incident;
	}

	/**
	 * Retrieves the arguments which caused this error.
	 * @return     array<string, ValidationArgument> An array of ValidationArgument.
	 * @since      1.0.0
	 */
	public function getArguments()
	{
		return $this->arguments;
	}

	/**
	 * Checks if this error was caused for the given argument
	 * @param      ValidationArgument $argument The argument.
	 * @return     bool The result.
	 * @since      1.0.0
	 */
	public function hasArgument(ValidationArgument $argument)
	{
		return isset($this->arguments[$argument->getHash()]);
	}
	
	/**
	 * Retrieves the fields which caused this error.
	 * @return     array<int, string> An array of field names.
	 * @since      1.0.0
	 */
	public function getFields()
	{
		$fields = [];
		foreach($this->arguments as $argument) {
			$fields[] = $argument->getName();
		}
		return $fields;
	}

	/**
	 * Checks if this error was caused for the given field
	 * @param      string $fieldname The name of the field to check.
	 * @return     bool The result.
	 * @since      1.0.0
	 */
	public function hasField($fieldname)
	{
		return $this->hasArgument(new ValidationArgument($fieldname));
	}

	/**
	 * Returns the error to its initial state for reuse across requests.
	 *
	 * Drops the affected arguments, the message and the name, and detaches the
	 * error from the incident it belonged to, without touching that incident.
	 */
	public function reset() : void
	{
		$this->arguments = [];
		$this->incident = null;
		$this->message = '';
		$this->name = '';
	}
}

?>