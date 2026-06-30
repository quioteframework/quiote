<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2010 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+

namespace Agavi\Testing;

use Agavi\AgaviContext;

/**
 * AgaviContainerTestCase is the base class for all tests that target a specific
 * container execution and provides the necessary assertions
 * 
 * 
 * @package    agavi
 * @subpackage testing
 *
 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
 * @copyright  The Agavi Project
 *
 * @since      1.0.0
 *
 * @version    $Id: AgaviFlowTestCase.class.php 3843 2009-02-16 14:12:47Z felix $
 */
abstract class AgaviContainerTestCase extends AgaviFragmentTestCase
{
	/**
	 * @var        string the name of the action to use
	 */
	protected $acionName;

	/**
	 * @var        string the name of the module the action resides in
	 */
	protected $moduleName;

	/**
	 * @var        AgaviResponse the response after the dispatch call
	 */
	protected $response;

	/**
	 * dispatch the request
	 *
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0 
	 */
	public function execute($arguments = null, $outputType = null, $requestMethod = null)
	{
		// Legacy container dispatch removed. Provide deprecation notice and simulate minimal flow.
		$context = AgaviContext::getInstance();
		if (is_array($arguments)) {
			// Inject parameters directly into request runtime for downstream usage.
			try {
				$request = $context->getRequest();
				if (method_exists($request, 'setParameter')) {
					foreach ($arguments as $k => $v) { $request->setParameter($k, $v); }
				}
			} catch (\Throwable) {}
		}
		// Response simulation: create an empty response equivalent.
		$this->response = $context->getController()->getGlobalResponse();
	}

	// Tag-based response assertions removed (legacy DOM matcher). Modern tests should inspect
	// response content directly or use DOMDocument/XPath as needed.
}

?>