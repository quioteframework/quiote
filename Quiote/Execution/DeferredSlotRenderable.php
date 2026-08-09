<?php

namespace Quiote\Execution;

use Quiote\Context;

/**
 * A slot whose action is not dispatched until its content is actually asked for.
 *
 * Returned by `View::slot()`, which captures the target module, action, parameters and
 * output type and hands the template this object. Because it is `\Stringable`, echoing it
 * inside a template is what triggers the dispatch; a slot a template never prints costs
 * nothing. The rendered content is memoized, so the slot action runs at most once per
 * instance.
 *
 * Dispatch resolves the parent {@see \Quiote\Request\WebRequest} and the
 * {@see SlotDispatcher} from the container at that moment. A failure is rethrown so the
 * error-handling middleware decides what the client sees, and nothing is memoized.
 *
 * {@see getModule()}, {@see getAction()}, {@see getOutputType()} and {@see getArguments()}
 * describe the pending dispatch without performing it; {@see toArray()} does perform it,
 * since it reports the content length.
 */
class DeferredSlotRenderable implements SlotRenderable, \Stringable
{
    private ?string $rendered = null;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(private readonly \Quiote\Context $context, private readonly string $module, private readonly string $action, private readonly array $parameters = [], private readonly ?string $outputType = null)
    {
    }

    /**
     * Renders the slot on first call and returns its content.
     *
     * The result is memoized, so the slot action is dispatched at most once per instance no
     * matter how often a template stringifies it. Dispatch resolves the parent WebRequest and
     * the SlotDispatcher from the container and hands them the module, action, parameters and
     * output type captured at construction. A failure during dispatch is recorded to PHP's own
     * error log with the slot's identity and a truncated trace when debug logging is on, then
     * rethrown so the error-handling middleware decides what the client sees; nothing is
     * memoized in that case.
     */
    public function getContent(): string
    {
        $logger = \Quiote\Logging\Log::for($this);
        $logExceptions = $logger->isEnabled(\Quiote\Logging\Level::Debug);

        if ($this->rendered !== null) {
            return $this->rendered;
        }

        // getRequest() rather than the removed getCurrentPsrRequest(): the same object, and it
        // rebuilds one if the request-scoped instance was cleared, which is strictly better here than
        // the exception the null case used to raise.
        $parentRequest = $this->context->getContainer()->get(\Quiote\Request\WebRequest::class);
        try {
            $pid = spl_object_id($parentRequest);
            $has = $parentRequest->getAttribute(\Quiote\Execution\SlotStack::class) ? '1' : '0';
            if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) { $logger->debug(sprintf('[DeferredSlotRenderable] DeferredSlotRenderable parentRequest id=%d slotstack=%s module=%s action=%s', $pid, $has, $this->module, $this->action)); }
        } catch (\Throwable) {
            $logger->debug('[DeferredSlotRenderable] DeferredSlotRenderable parentRequest (no id available)');
        }

        $dispatcher = $this->context->getContainer()->get(\Quiote\Execution\SlotDispatcher::class);
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
                } catch(\Throwable $logFailure) {
                    // The original throwable is what matters and must not be displaced by a failure
                    // to record it, so this cannot escalate through the logger it just lost. Noted
                    // through PHP's own error log, which does not depend on ours.
                    \error_log(
                        '[DeferredSlotRenderable] could not log a slot render failure: '
                        . $logFailure->getMessage()
                    );
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
    /** Returns the module the slot action will be dispatched from. */
    public function getModule(): string
    {
        return $this->module;
    }
    /** Returns the name of the slot action to dispatch. */
    public function getAction(): string
    {
        return $this->action;
    }
    /** Returns the output type the slot will render for, or null to let dispatch pick one. */
    public function getOutputType(): ?string
    {
        return $this->outputType;
    }
    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->parameters;
    }
    public function __toString(): string
    {
        return $this->getContent();
    }
    /**
     * @return array<string, mixed>
     */
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
