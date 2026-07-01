<?php
namespace Quiote\Validator;


/**
 * ValidationArgument is a tuple of argument name and source that specifies 
 * the argument to validate.
 * @since      1.0.0
 * @version    1.0.0
 */
class ValidationArgument
{
	/**
	 * @var        string the name of the source.
	 */
	protected $source;
	
	/**
     * Create a new ValidationArgument instance.
     * @param      string the name of the argument.
     * @param      string the name of the source, if null, "parameters" is used.
     * @since      1.0.0
     * @param string $name
     */
    public function __construct(/**
     * @var        string the name of the argument.
     */
    protected $name, $source = null)
	{
		if($source === null) {
			$source = "parameters";
		}
		$this->source = $source;
	}
	
	/**
	 * Retrieve the name of the argument for this instance.
	 * @return     string the name of the argument
	 * @since      1.0.0
	 */
	public function getName()
	{
		return $this->name;
	}
	
	/**
	 * Retrieve the name of the source for this instance.
	 * @return     string the name of the source.
	 * @since      1.0.0
	 */
	public function getSource()
	{
		return $this->source;
	}
	
	/**
	 * Get a unique hash value for this ValidationArgument.
	 * @return     string the hash value
	 * @since      1.0.0
	 */
	public function getHash()
	{
		return sprintf('%s/%s', $this->source, $this->name);
	}
}

?>