<?php

namespace Sandbox;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviRequestDataHolder;

/**
 * Index Action - simplified namespace demo
 */
class IndexAction extends AgaviAction
{
    /**
     * Executes the action.
     *
     * @return     string The view to execute.
     */
    public function execute(AgaviRequestDataHolder $rd)
    {
        // Return "Success" view
        return 'Success';
    }
}
