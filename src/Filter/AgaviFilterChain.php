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
class AgaviFilterChain
{
	protected array $preFilters = [];
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
	public function execute($container, ?callable $actionCallback = null)
	{
		
		$logger = null;
		if (method_exists($container, 'getContext') && $container->getContext() && method_exists($container->getContext(), 'getLoggerManager') && $container->getContext()->getLoggerManager()) {
			$logger = $container->getContext()->getLoggerManager();
		}
		
		// Execute pre-filters, but stop if any sets a forward container
		foreach($this->preFilters as $name => $filter) {
			// Check forward container status BEFORE filter execution
			$nextBefore = $container->getNext();
			$debugMsg = "[" . date('Y-m-d H:i:s') . "] BEFORE $name: forward container = " . ($nextBefore ? $nextBefore->getModuleName() . "/" . $nextBefore->getActionName() : "NULL") . "\n";
			
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
}
