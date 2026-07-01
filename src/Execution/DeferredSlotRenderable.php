<?php

namespace Agavi\Execution;

use Agavi\AgaviContext;

class DeferredSlotRenderable implements SlotRenderable, \Stringable
{
    private ?string $rendered = null;

    public function __construct(private readonly \Agavi\AgaviContext $context, private readonly string $module, private readonly string $action, private readonly array $parameters = [], private readonly ?string $outputType = null)
    {
    }

    public function getContent(): string
    {
        $logger = \Agavi\Logging\Log::for($this);
        $logExceptions = \Agavi\Util\DebugFlags::$slotExceptions;

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
            if ($logger->isEnabled(\Agavi\Logging\Level::Debug)) { $logger->debug(sprintf('[DeferredSlotRenderable] DeferredSlotRenderable parentRequest id=%d slotstack=%s module=%s action=%s', $pid, $has, $this->module, $this->action)); }
        } catch (\Throwable) {
            $logger->debug('[DeferredSlotRenderable] DeferredSlotRenderable parentRequest (no id available)');
        }

        $dispatcher = $this->context->getSlotDispatcher();
        try {
            $slotContent = $dispatcher->dispatchSlotContent($parentRequest, $this->module, $this->action, $this->parameters, $this->outputType);
            $this->rendered = $slotContent->getContent();
            return $this->rendered;
        } catch(\Throwable $e) {
            if($logExceptions) {
                try {
                    $payload = json_encode([
                        'phase' => 'deferred',
                        'module' => $this->module,
                        'action' => $this->action,
                        'parameters' => $this->parameters,
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                        'trace' => $this->truncateTrace($e->getTraceAsString()),
                        'time' => date('c'),
                    ]);
                    \error_log('SLOT_EXCEPTION ' . $payload);
                } catch(\Throwable) {
                    // swallow logging errors to not mask original exception
                }
            }
            throw $e; // rethrow so global middleware handles it
        }
    }

    private function truncateTrace(string $trace, int $max = 8000): string
    {
        if(strlen($trace) <= $max) { return $trace; }
        return substr($trace, 0, $max) . '... [truncated]';
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
