<?php
use Quiote\Routing\IRoutingCallback;
use Quiote\Routing\RoutingCallback;

/**
 * Mock routing callbacks for testing
 * @since      1.0.0
 * @version    1.0.0
 */
class TestMatchingRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onMatched(array &$parameters, $legacyContainer = null) { return true; }
}

class TestNonMatchingRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onMatched(array &$parameters, $legacyContainer = null) { return false; }
}

class TestOnNotMatchedRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onNotMatched($legacyContainer = null)
    {
        // Mark attribute directly via context routing callbacks pool if needed
        if($this->getContext() && $this->getContext()->getRequest()) {
            $this->getContext()->getRequest()->setAttribute('quiote.routing.callbacks.on_not_matched', true);
        }
        return true;
    }
}

class TestGenWithParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['callback_param'] = 'added_by_callback';
        return true;
    }
}

class TestGenWithUnescapedParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['callback_param'] = 'added/by/callback';
        $userOptions['quiote.routing.unescape'][] = 'callback_param';
        return true;
    }
}

class TestGenUnsetRouteParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        unset($userParameters['number']);
        return true;
    }
}

class TestGenUnsetExtraParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        unset($userParameters['extra']);
        return true;
    }
}

class TestGenNullifyRouteParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['number'] = null;
        return true;
    }
}

class TestGenNullifyExtraParamRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userParameters['extra'] = null;
        return true;
    }
}

class TestGenSetPrefixAndPostfixRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        $userOptions['prefix'] = 'prefix/';
        $userOptions['postfix'] = '/postfix';
        return true;
    }
}

class TestGenSetPrefixAndPostfixIntoRouteRoutingCallback extends RoutingCallback
{
    #[\Override]
    public function onGenerate(array $defaultParameters, array &$userParameters, array &$userOptions)
    {
        if (isset($userParameters['number'])) {
            $userParameters['number'] = 'prefix/' . $userParameters['number'] . '/postfix';
        }
        return true;
    }
}
