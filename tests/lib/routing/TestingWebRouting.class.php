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

	public function setInput(string $input): void
	{
		$this->input = $input;
	}

	/**
	 * @param array<mixed> $data
	 */
	public function setRoutingSource(string $name, array $data, ?string $type = null): void
	{
		if(null === $type) {
			$type = 'RoutingArraySource';
		}
		$this->sources[$name] = new RoutingArraySource($data);
	}

	/**
	 * @param array<string,mixed> $parameters
	 */
	public function setInputParameters(array $parameters): void
	{
		$this->inputParameters = $parameters;
	}
}

?>