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
        // Add debug logging to file
        $debugMsg = "[" . date('Y-m-d H:i:s') . "] AgaviSecurityFilter::execute() called for: " . $container->getModuleName() . "/" . $container->getActionName() . "\n";
        file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);
        
        static $handlingRedirects = [];
        $actionKey = $container->getModuleName() . '/' . $container->getActionName();
        if(isset($handlingRedirects[$actionKey])) {
            $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: Already handling redirects for $actionKey, returning early\n";
            file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);
            return;
        }

        if($container->isSecurityForwarded()) {
            $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: Container is security forwarded, allowing access\n";
            file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);
            return;
        }

        $context    = $this->getContext();
        $user       = $context->getUser();
        $actionInstance = $container->getActionInstance();

        $isSecure = $actionInstance->isSecure();
        $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: Action isSecure() = " . ($isSecure ? 'true' : 'false') . "\n";
        file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);

        if(!$isSecure) {
            $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: Action is not secure, allowing access\n";
            file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);
            return;
        }

        $isAuthenticated = $user->isAuthenticated();
        $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: User isAuthenticated() = " . ($isAuthenticated ? 'true' : 'false') . "\n";
        file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);

        $credential = $actionInstance->getCredentials();
        $hasCredentials = ($credential === null || $user->hasCredentials($credential));
        $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: Required credentials = " . var_export($credential, true) . ", hasCredentials = " . ($hasCredentials ? 'true' : 'false') . "\n";
        file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);

        if($isAuthenticated && $hasCredentials) {
            // user has access, allow filter chain to continue
            $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: Access granted - user is authenticated and has credentials\n";
            file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);
            return;
        } else {
            try {
                $handlingRedirects[$actionKey] = true;

                if($isAuthenticated) {
                    $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: User authenticated but lacks credentials, forwarding to 'secure'\n";
                    file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);
                    $container->setNext($container->createSystemActionForwardContainer('secure'));
                } else {
                    $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: User not authenticated, forwarding to 'login'\n";
                    file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);
                    $forwardContainer = $container->createSystemActionForwardContainer('login');
                    $forwardContainer->setSecurityForwarded(true);
                    $container->setNext($forwardContainer);
                    $debugMsg = "[" . date('Y-m-d H:i:s') . "] Security filter: Login forward container created and set\n";
                    file_put_contents('/code/log/debug.log', $debugMsg, FILE_APPEND | LOCK_EX);
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