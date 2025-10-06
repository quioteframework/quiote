<?php

namespace Agavi\Execution;

use Agavi\AgaviContext;
use Agavi\Logging\AgaviDebugLogger;

class DeferredSlotRenderable implements SlotRenderable
{
    private \Agavi\AgaviContext $context;
    private string $module;
    private string $action;
    private ?string $outputType;
    private array $parameters;
    private ?string $rendered = null;

    public function __construct(AgaviContext $context, string $module, string $action, array $parameters = [], ?string $outputType = null)
    {
        $this->context = $context;
        $this->module = $module;
        $this->action = $action;
        $this->parameters = $parameters;
        $this->outputType = $outputType;
    }

    public function getContent(): string
    {
        $dsr = getenv('AGAVI_DEBUG_SLOT_RENDERER') ?? false;

        if ($this->rendered !== null) {
            return $this->rendered;
        }

        $parentRequest = $this->context->getCurrentPsrRequest();
        if (!$parentRequest) {
            throw new \RuntimeException('No current PSR request available for deferred slot dispatch');
        }
        try {
            $pid = spl_object_id($parentRequest);
            $has = $parentRequest->getAttribute(\Agavi\Execution\SlotStack::class) ? '1' : '0';
            if ($dsr) AgaviDebugLogger::debug(sprintf('[DeferredSlotRenderable] DeferredSlotRenderable parentRequest id=%d slotstack=%s module=%s action=%s', $pid, $has, $this->module, $this->action), $this->context);
        } catch (\Throwable $_e) {
            AgaviDebugLogger::debug('[DeferredSlotRenderable] DeferredSlotRenderable parentRequest (no id available)', $this->context);
        }

        $dispatcher = $this->context->getSlotDispatcher();
        $slotContent = $dispatcher->dispatchSlotContent($parentRequest, $this->module, $this->action, $this->parameters, $this->outputType);
        $this->rendered = $slotContent->getContent();
        return $this->rendered;
    }

    // Compatibility getters so code expecting SlotContent-like API continues to work
    public function getModule(): string
    {
        return $this->module;
    }
    public function getAction(): string
    {
        return $this->action;
    }
    public function getOutputType(): ?string
    {
        return $this->outputType;
    }
    public function getArguments(): array
    {
        return $this->parameters;
    }
    public function __toString(): string
    {
        return $this->getContent();
    }
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'action' => $this->action,
            'output_type' => $this->outputType,
            'arguments' => $this->parameters,
            'content_length' => strlen($this->getContent()),
        ];
    }
}
