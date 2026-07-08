<?php

use Quiote\Routing\RoutingCallback;

class GenUnsetExtraParamRoutingCallback extends RoutingCallback
{
	/**
	 * Gets executed when the route of this callback is about to be reverse 
	 * generated into an URL.
	 * @param      array<string, mixed> $defaultParameters The default parameters stored in the route.
	 * @param      array<string, mixed> $userParameters The parameters the user supplied to Routing::gen().
	 * @param      array<string, mixed> $userOptions The options the user supplied to Routing::gen().
	 * @return     bool  Whether this route part should be generated.
	 * @since      1.0.0
	 */
	#[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
	{
		// unsetting a value in callback is supposed to have no impact
		unset($userParameters['extra']);
		return true;
	}
}

?>