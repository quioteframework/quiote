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

use Agavi\Routing\AgaviIRoutingCallback;
use Agavi\Routing\AgaviRoutingCallback;

/**
 * Mock routing callbacks for testing
 *
 * @package    agavi
 * @subpackage routing
 *
 * @author     Updated for PHP 8.4
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      1.0.0
 *
 * @version    $Id$
 */
class TestMatchingRoutingCallback extends AgaviRoutingCallback
{
    public function onMatched(array &$parameters, $legacyContainer = null) { return true; }
}

class TestNonMatchingRoutingCallback extends AgaviRoutingCallback
{
    public function onMatched(array &$parameters, $legacyContainer = null) { return false; }
}

class TestOnNotMatchedRoutingCallback extends AgaviRoutingCallback
{
    public function onNotMatched($legacyContainer = null)
    {
        // Mark attribute directly via context routing callbacks pool if needed
        if($this->getContext() && $this->getContext()->getRequest()) {
            $this->getContext()->getRequest()->setAttribute('agavi.routing.callbacks.on_not_matched', true, 'org.agavi.routing');
        }
        return true;
    }
}

class TestGenWithParamRoutingCallback extends AgaviRoutingCallback
{
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['callback_param'] = 'added_by_callback';
        return true;
    }
}

class TestGenWithUnescapedParamRoutingCallback extends AgaviRoutingCallback
{
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['callback_param'] = 'added/by/callback';
        $userOptions['agavi.routing.unescape'][] = 'callback_param';
        return true;
    }
}

class TestGenUnsetRouteParamRoutingCallback extends AgaviRoutingCallback
{
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        unset($userParameters['number']);
        return true;
    }
}

class TestGenUnsetExtraParamRoutingCallback extends AgaviRoutingCallback
{
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        unset($userParameters['extra']);
        return true;
    }
}

class TestGenNullifyRouteParamRoutingCallback extends AgaviRoutingCallback
{
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['number'] = null;
        return true;
    }
}

class TestGenNullifyExtraParamRoutingCallback extends AgaviRoutingCallback
{
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['extra'] = null;
        return true;
    }
}

class TestGenSetPrefixAndPostfixRoutingCallback extends AgaviRoutingCallback
{
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userOptions['prefix'] = 'prefix/';
        $userOptions['postfix'] = '/postfix';
        return true;
    }
}

class TestGenSetPrefixAndPostfixIntoRouteRoutingCallback extends AgaviRoutingCallback
{
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        if (isset($userParameters['number'])) {
            $userParameters['number'] = 'prefix/' . $userParameters['number'] . '/postfix';
        }
        return true;
    }
}
