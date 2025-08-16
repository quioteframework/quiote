<?php
namespace Agavi\Execution;

use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Response\AgaviResponse;

/**
 * Carries IO artifacts for an execution (request data + response + validation report placeholder).
 */
final class ExecutionIO
{
    public function __construct(
        public AgaviRequestDataHolder $requestData,
        public AgaviResponse $response
    ) {}
}
