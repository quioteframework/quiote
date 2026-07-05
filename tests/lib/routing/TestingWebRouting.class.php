<?php
use Quiote\Routing\Routing;
use Quiote\Routing\RoutingArraySource;

/**
 * TestingRouting allows access to some internal routing properties and
 * extends the abtract base class to make it testable.
 * @since      1.0.0
 * @version    1.0.0
 */
class TestingWebRouting extends Routing
{
	/** @return array{0: \Symfony\Component\Routing\RouteCollection, 1: array<mixed>} */
	protected function build(): array
	{
		return [new \Symfony\Component\Routing\RouteCollection(), []];
	}

	public function setInput($input)
	{
		$this->input = $input;
	}

	public function setRoutingSource($name, $data, $type = null)
	{
		if(null === $type) {
			$type = 'RoutingArraySource';
		}
		$this->sources[$name] = new RoutingArraySource($data);
	}

	public function setInputParameters(array $parameters)
	{
		$this->inputParameters = $parameters;
	}
}

?>