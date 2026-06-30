<?php

use Agavi\Exception\AgaviException;
use Agavi\Routing\AgaviRoutingCallback;

class OnNotMatchedRoutingCallback extends AgaviRoutingCallback
{
	/**
	 * Gets executed when the route of this callback route did not match.
	 *
	 * @param      AgaviExecutionContainer The original execution container.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	#[\Override]
    public function onNotMatched($legacyContainer = null)
	{
		throw new AgaviException('Not Matched');
		return;
	}
}

?>