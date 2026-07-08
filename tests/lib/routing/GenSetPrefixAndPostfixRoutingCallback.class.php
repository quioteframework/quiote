<?php

use Quiote\Routing\RoutingCallback;
use Quiote\Routing\RoutingValue;

class GenSetPrefixAndPostfixRoutingCallback extends RoutingCallback
{
	/**
	 * @param      array<string, mixed> $defaultParameters The default parameters stored in the route.
	 * @param      array<string, mixed> $userParameters The parameters the user supplied to Routing::gen().
	 * @param      array<string, mixed> $userOptions The options the user supplied to Routing::gen().
	 */
	#[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
	{
		$userParameters['number'] = (new RoutingValue('value'))->setPrefix('prefix-')->setPostfix('-postfix');
		return true;
	}
}

?>