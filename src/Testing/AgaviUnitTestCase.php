<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
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
use Agavi\Request\AgaviWebRequest;

/**
 * AgaviUnitTestCase is the base class for all unit testcases and provides
 * the necessary assertions
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
 * @version    $Id$
 */
abstract class AgaviUnitTestCase extends AgaviPhpUnitTestCase implements AgaviIUnitTestCase
{
	/**
	 * @var        string the name of the context to use, null for default context
	 */
	protected $contextName = null;
	
	/**
	 * Return the context defined for this test (or the default one).
	 *
	 * @return     AgaviContext The context instance defined for this test.
	 *
	 * @author     Felix Gilcher <felix.gilcher@bitextender.com>
	 * @since      1.0.0
	 */
	public function getContext()
	{
		return AgaviContext::getInstance($this->contextName);
	}

	/**
	 * Convenience factory for PSR-compatible AgaviWebRequest instances in tests.
	 *
	 * @param array<string,mixed> $parameters runtime parameters to seed.
	 */
	protected function newWebRequest(array $parameters = []): AgaviWebRequest
	{
		$request = new AgaviWebRequest();
		$request->initialize($this->getContext());
		foreach ($parameters as $key => $value) {
			$request->setParameter($key, $value);
		}
		return $request;
	}
}