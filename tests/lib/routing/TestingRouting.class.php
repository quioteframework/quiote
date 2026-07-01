<?php
use Quiote\Routing\Routing;
use Quiote\Routing\RoutingArraySource;
use Quiote\Routing\WebRouting;

/**
 * TestingRouting allows access to some internal routing properties and
 * extends the abtract base class to make it testable.
 * @since      1.0.0
 * @version    1.0.0
 */
class TestingRouting extends WebRouting
{
	protected $forcedInput = null;
	protected $errorActions = [];
	
	/**
	 * Set the input to use for routing
	 */
	public function forceInput(string $input): void
	{
		$this->forcedInput = $input;
	}
	

	
	public function setRoutingSource($name, $data, $type = null)
	{
		if(null === $type) {
			$type = 'RoutingArraySource';
		}
		$this->sources[$name] = new RoutingArraySource($data);
	}
	
	#[\Override]
    public function parseRouteString(string $str): array
	{
		return parent::parseRouteString($str);
	}
	
	/**
	 * Override the input property for execution
	 */
	public function execute()
	{
		if ($this->forcedInput !== null) {
			$this->input = $this->forcedInput;
		}
		return parent::execute();
	}
}

?>