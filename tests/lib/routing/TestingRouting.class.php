<?php
use Quiote\Routing\Routing;
use Quiote\Routing\RoutingArraySource;

/**
 * TestingRouting allows access to some internal routing properties and
 * extends the abtract base class to make it testable.
 * @since      1.0.0
 * @version    1.0.0
 */
class TestingRouting extends Routing
{
	protected ?string $forcedInput = null;

	/** @var array<mixed> */
	protected array $errorActions = [];

	/** @return array{0: \Symfony\Component\Routing\RouteCollection, 1: array<mixed>} */
	protected function build(): array
	{
		return [new \Symfony\Component\Routing\RouteCollection(), []];
	}

	/**
	 * Set the input to use for routing
	 */
	public function forceInput(string $input): void
	{
		$this->forcedInput = $input;
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
	
	#[\Override]
    public function parseRouteString(string $str): array
	{
		return parent::parseRouteString($str);
	}
	
	/**
	 * Apply the forced input (if any) and delegate to match().
	 * @return array<string,mixed>
	 */
	public function execute(): array
	{
		if ($this->forcedInput !== null) {
			$this->input = $this->forcedInput;
		}
		return $this->match($this->input);
	}
}

?>