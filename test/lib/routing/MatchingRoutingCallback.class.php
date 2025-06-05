<?php

use Agavi\Controller\AgaviExecutionContainer;
use Agavi\Routing\AgaviRoutingCallback;

class MatchingRoutingCallback extends AgaviRoutingCallback
{
	public function onMatched(array &$parameters, AgaviExecutionContainer $container)
	{
		$parameters['callback'] = 'set';
		return true;
	}
}

?>