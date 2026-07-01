<?php

namespace Sandbox;

use Quiote\Action\Action;
use Quiote\Request\WebRequest ;

/**
 * Index Action - simplified namespace demo
 */
class IndexAction extends Action
{
    /**
     * Executes the action.
     * @return     string The view to execute.
     */
    public function execute(WebRequest $rd)
    {
        // Return "Success" view
        return 'Success';
    }
}
