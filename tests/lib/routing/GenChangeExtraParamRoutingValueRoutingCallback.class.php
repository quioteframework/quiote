<?php

use Quiote\Routing\RoutingCallback;

class GenChangeExtraParamRoutingValueRoutingCallback extends RoutingCallback
{
	/**
	 * Gets executed when the route of this callback is about to be reverse 
	 * generated into an URL.
	 * @param      array The default parameters stored in the route.
	 * @param      array The parameters the user supplied to Routing::gen().
	 * @param      array The options the user supplied to Routing::gen().
	 * @return     bool  Whether this route part should be generated.
	 * @since      1.0.0
	 */
	#[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
	{
		$userParameters['extra'] = $this->getContext()->getRouting()->createValue('callback data');
		return true;
	}
}

?>