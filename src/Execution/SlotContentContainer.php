<?php
namespace Agavi\Execution;

use Agavi\Controller\AgaviExecutionContainer; // extend for compatibility (methods overridden minimally)
use Agavi\Response\AgaviResponse;

/**
 * Minimal surrogate container holding pre-rendered slot content.
 * Temporary bridge so AgaviTemplateLayer can treat slots uniformly while we phase out full containers.
 */
class SlotContentContainer extends AgaviExecutionContainer implements SlotRenderable
{
    private AgaviResponse $syntheticResponse;

    public function __construct(string $content)
    {
        // We intentionally do not call parent constructor (heavy setup); just fake response.
        $this->syntheticResponse = new class($content) extends AgaviResponse { public function __construct(private string $c){ $this->content = $c; } }; // AgaviResponse sets content property
    }

    public function execute()
    {
        return $this->syntheticResponse; // present same API (getContent())
    }

    public function getContent(): string
    {
        return (string)$this->syntheticResponse->getContent();
    }
}
