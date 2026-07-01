<?php
namespace Quiote\Filter;

use Quiote\Controller\ExecutionContainer;
use Symfony\Contracts\Service\ResetInterface;

/**
 * FilterChain manages registered filters for a specific context.
 * @since      1.0.0
 * @version    1.0.0
 */
class FilterChain implements ResetInterface
{
	/** @var Filter[] */
	protected array $preFilters = [];
	/** @var Filter[] */
	protected array $postFilters = [];
	protected array $names = [];
	protected int $type;

	const TYPE_GLOBAL = 1;
	const TYPE_ACTION = 2;

	public function setType(int $type)
	{
		$this->type = $type;
	}

	public function register($filter, string $name)
	{
		if (method_exists($filter, 'isPostFilter') && $filter->isPostFilter()) {
			$this->registerPost($filter, $name);
		} else {
			$this->registerPre($filter, $name);
		}
	}

	public function registerPre($filter, string $name)
	{
		$this->preFilters[$name] = $filter;
	}

	public function registerPost($filter, string $name)
	{
		$this->postFilters[$name] = $filter;
	}

	/**
	 * Executes all pre-filters, then the action, then all post-filters.
	 * This simplified version respects forward containers set by filters.
	 */
	public function execute(ExecutionContainer $container, ?callable $actionCallback = null)
	{
		
		// Execute pre-filters, but stop if any sets a forward container
		/** @var Filter $filter */
		foreach($this->preFilters as $filter) {
			// Check forward container status BEFORE filter execution
			$nextBefore = $container->getNext();
			
			// Execute the filter (this simplified version doesn't pass the filter chain)
			$filter->execute($this, $container);
			
			// Check forward container status AFTER filter execution
			$nextAfter = $container->getNext();
			
			// If a filter set a forward container, stop executing further filters
			if ($container->getNext() !== null) {
				return; // Don't execute action or post-filters
			}
		}
		
		// Execute action only if no forward container was set
		if ($container->getNext() === null && $actionCallback !== null) {
			$actionCallback($container);
		} 
		
		// Execute post-filters
		foreach($this->postFilters as $filter) {
			$filter->execute($this, $container);
		}
		
	}

	public function initialize($context, $parameters = [])
	{
		// No-op for compatibility with factory system.
		// Parameters are intentionally unused
	}

	/**
	 * Reset filter chain state for FrankenPHP worker compatibility.
	 * Clears filter chain properties that could leak between requests.
	 * @since      1.0.0
	 */
	public function reset(): void
	{
		$this->preFilters = [];
		$this->postFilters = [];
		$this->names = [];

		// Note: $this->type is typically set during initialization and doesn't need reset
	}
}
