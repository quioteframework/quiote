<?php

use Agavi\Response\AgaviWebResponse;
use Agavi\Controller\AgaviOutputType;

class TestConcreteResponse extends AgaviWebResponse
{
    // Use AgaviWebResponse default implementation; provide minimal overrides where tests expect no-op initialization/send
    #[\Override]
    public function initialize($context = null, array $parameters = []) { /* no-op for test */ }
    #[\Override]
    public function send(?AgaviOutputType $outputType = null) { /* no-op for test */ }
}

?>