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
use Agavi\Exception\AgaviException;

/**
 * AgaviBasicSecurityFilter checks security by calling the getCredentials() 
 * method of the action. Once the credential has been acquired, 
 * AgaviBasicSecurityFilter verifies the user has the same credential 
 * by calling the hasCredentials() method of SecurityUser.
 *
 * @package    agavi
 * @subpackage filter
 *
 * @author     Sean Kerr <skerr@mojavi.org>
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
class AgaviSecurityFilter extends AgaviFilter implements AgaviIActionFilter, AgaviISecurityFilter
{
    /**
     * Execute this filter.
     *
     * @param      AgaviFilterChain The filter chain.
     * @param      AgaviExecutionContainer The current execution container.
     *
     * @author     David Zülke <dz@bitxtender.com>
     * @author     Sean Kerr <skerr@mojavi.org>
     * @since      0.9.0
     */
    #[\Override]
    public function execute(AgaviFilterChain $filterChain, AgaviExecutionContainer $container)
    {
        
        static $handlingRedirects = [];
        $actionKey = $container->getModuleName() . '/' . $container->getActionName();
        if(isset($handlingRedirects[$actionKey])) {
            return;
        }

        if($container->isSecurityForwarded()) {
            return;
        }

        $context    = $this->getContext();
        $user       = $context->getUser();
        $actionInstance = $container->getActionInstance();

        $isSecure = $actionInstance->isSecure();
       
        if(!$isSecure) {
            return;
        }

        $isAuthenticated = $user->isAuthenticated();
       
        $credential = $actionInstance->getCredentials();
        $hasCredentials = ($credential === null || $user->hasCredentials($credential));
       
        if($isAuthenticated && $hasCredentials) {
            // user has access, allow filter chain to continue
            return;
        } else {
            try {
                $handlingRedirects[$actionKey] = true;

                if($isAuthenticated) {
                    $container->setNext($container->createSystemActionForwardContainer('secure'));
                } else {
                    $forwardContainer = $container->createSystemActionForwardContainer('login');
                    $forwardContainer->setSecurityForwarded(true);
                    $container->setNext($forwardContainer);
                    return;
                }
            } finally {
                unset($handlingRedirects[$actionKey]);
            }
        }
    }

    public function isPostFilter(): bool
    {
        return false;
    }
}