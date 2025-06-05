<?php

use Agavi\Controller\AgaviExecutionContainer;
use Agavi\Routing\AgaviRoutingCallback;

class NonMatchingRoutingCallback extends AgaviRoutingCallback
{
	public function onMatched(array &$parameters, AgaviExecutionContainer $container)
	{
		return false;
	}
}

?>