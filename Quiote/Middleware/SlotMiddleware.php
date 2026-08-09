<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\SlotStack;

/**
 * SlotMiddleware: establishes a SlotStack in request attributes for nested slot/sub-action rendering.
 * Later stages (DispatchMiddleware or a future SlotDispatcher) can push/pop keys as they perform slot executions.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', priority: 10)]
class SlotMiddleware implements MiddlewareInterface
{
    public const ATTR = SlotStack::class;
    public function __construct(private readonly ?\Quiote\Context $context = null) {}

    /**
     * Attaches a SlotStack to the request so downstream code can render nested slots.
     *
     * Does nothing if the request already carries one, which is what keeps a
     * slot rendered through a nested pipeline from starting a fresh stack. On a
     * fresh request the new stack is seeded with the `_original_psr_request`
     * attribute set by MiddlewarePipeline, so slot parameters are read from the
     * request as it was before validation pruned it.
     *
     * When a context was injected, the rewritten request is republished through
     * RequestState so anything reading Context::getRequest() sees the instance
     * carrying the stack. A failure to publish is logged as a warning and not
     * rethrown; the consequence is that a slot rendered from context-read code
     * will not find a stack.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request->getAttribute(self::ATTR)) {
            $slotStack = new SlotStack();
            // Save original request from MiddlewarePipeline for slot parameter access
            $originalRequest = $request->getAttribute('_original_psr_request');
            if ($originalRequest instanceof ServerRequestInterface) {
                $slotStack->setOriginalRequest($originalRequest);
            }
            $request = $request->withAttribute(self::ATTR, $slotStack);
            // Log request identity and presence of SlotStack for debugging in FrankenPHP
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                try {
                    $id = spl_object_id($request);
                    $has = $request->getAttribute(self::ATTR) ? '1' : '0';
                    \Quiote\Logging\Log::for($this)->debug(sprintf('[Slot SlotStack set on request id=%d has=%s', $id, $has));
                } catch (\Throwable) {
                    \Quiote\Logging\Log::for($this)->debug('[SlotMW] SlotStack set (unable to introspect request id)');
                }
            }
            // Inform context about the request instance change so it stays in sync
            if ($this->context !== null) {
                try {
                    $this->context->getContainer()->get(\Quiote\Request\RequestState::class)->publish($request);
                } catch (\Throwable $e) {
                    // The request carrying the SlotStack never reached the context, so a slot
                    // rendered from code reading Context::getRequest() cannot find one.
                    \Quiote\Logging\Log::for($this)->warning(
                        '[SlotMW] could not publish the request carrying the SlotStack: ' . $e->getMessage()
                    );
                }
            }
        } else {
            if (\Quiote\Logging\Log::for($this)->isEnabled(\Quiote\Logging\Level::Debug)) {
                \Quiote\Logging\Log::for($this)->debugWith(
                    fn(): string => sprintf(
                        '[SlotMW] SlotStack already present on request id=%d',
                        spl_object_id($request)
                    )
                );
            }
        }
        return $handler->handle($request);
    }
}
