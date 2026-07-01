<?php
namespace Quiote\Execution;

use Quiote\Controller\ExecutionContainer; // extend for compatibility (methods overridden minimally)
use Quiote\Response\WebResponse;

/**
 * Minimal surrogate container holding pre-rendered slot content.
 * Temporary bridge so TemplateLayer can treat slots uniformly while we phase out full containers.
 */
class SlotContentContainer extends ExecutionContainer implements SlotRenderable
{
    private readonly WebResponse $syntheticResponse;

    public function __construct(string $content)
    {
        // We intentionally do not call parent constructor (heavy setup); just fake response.
    $this->syntheticResponse = new class($content) extends WebResponse { public function __construct(private readonly string $c){ $this->content = $c; } }; // WebResponse sets content property
    }

    #[\Override]
    public function execute()
    {
        return $this->syntheticResponse; // present same API (getContent())
    }

    public function getContent(): string
    {
        return (string)$this->syntheticResponse->getContent();
    }
}
