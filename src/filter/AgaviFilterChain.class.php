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
	 * $actionCallback is a closure that runs the action.
	 */
	public function execute($container, callable $actionCallback = null)
	{
		$logger = null;
		if (method_exists($container, 'getContext') && $container->getContext() && method_exists($container->getContext(), 'getLoggerManager') && $container->getContext()->getLoggerManager()) {
			$logger = $container->getContext()->getLoggerManager();
		}
		$log = function($msg) use ($logger): void {
			if ($logger) {
				$logger->logDebug($msg);
			} else {
				error_log($msg);
			}
		};

		$log("AgaviFilterChain::execute() start for: " . $container->getModuleName() . " / " . $container->getActionName());

		if ($actionCallback === null) {
			// No-op: do not call $c->execute() after filters
			$actionCallback = function($c): void {};
		}
		foreach($this->preFilters as $name => $filter) {
			$log("Executing pre-filter: $name (" . $filter::class . ")");
			$filter->execute($container);
		}
		$log("Executing action callback");
		$actionCallback($container);
		foreach($this->postFilters as $name => $filter) {
			$log("Executing post-filter: $name (" . $filter::class . ")");
			$filter->execute($container);
		}
		$log("AgaviFilterChain::execute() end for: " . $container->getModuleName() . " / " . $container->getActionName());
	}

	public function register($filter, string $name)
	{
		if (method_exists($filter, 'isPostFilter') && $filter->isPostFilter()) {
			$this->registerPost($filter, $name);
		} else {
			$this->registerPre($filter, $name);
		}
	}

	public function initialize($context, $parameters = [])
	{
		// No-op for compatibility with factory system.
	}
}
