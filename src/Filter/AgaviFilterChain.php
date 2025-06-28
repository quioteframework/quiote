<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// | Based on the Mojavi3 MVC Framework, Copyright (c) 2003-2005 Sean Kerr.    |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\Filter;

use Agavi\Controller\AgaviExecutionContainer;
use Symfony\Contracts\Service\ResetInterface;

/**
 * AgaviFilterChain manages registered filters for a specific context.
 *
 * @package    agavi
 * @subpackage filter
 *
 * @author     Sean Kerr <skerr@mojavi.org>
 * @author     David Zülke <dz@bitxtender.com>
 * @author     Markus Lervik <markus.lervik@thejakamo.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
class AgaviFilterChain implements ResetInterface
{
	/** @var AgaviFilter[] */
	protected array $preFilters = [];
	/** @var AgaviFilter[] */
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
	public function execute(AgaviExecutionContainer $container, ?callable $actionCallback = null)
	{
		
		// Execute pre-filters, but stop if any sets a forward container
		/** @var AgaviFilter $filter */
		foreach($this->preFilters as $name => $filter) {
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
		foreach($this->postFilters as $name => $filter) {
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
	 *
	 * @author     Generated for FrankenPHP worker compatibility
	 * @since      1.1.0
	 */
	public function reset(): void
	{
		$this->preFilters = [];
		$this->postFilters = [];
		$this->names = [];

		// Note: $this->type is typically set during initialization and doesn't need reset
	}
}
