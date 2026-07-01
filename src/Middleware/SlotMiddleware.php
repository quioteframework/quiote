<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Execution\SlotStack;

/**
 * SlotMiddleware: establishes a SlotStack in request attributes for nested slot/sub-action rendering.
 * Later stages (DispatchMiddleware or a future SlotDispatcher) can push/pop keys as they perform slot executions.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'pre_routing', before: 'RoutingMiddleware')]
class SlotMiddleware implements MiddlewareInterface
{
    public const ATTR = SlotStack::class;
    public function __construct(private readonly ?\Agavi\AgaviContext $context = null) {}

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
            if (\Agavi\Logging\Log::for($this)->isEnabled(\Agavi\Logging\Level::Debug)) {
                try {
                    $id = spl_object_id($request);
                    $has = $request->getAttribute(self::ATTR) ? '1' : '0';
                    \Agavi\Logging\Log::for($this)->debug(sprintf('[Slot SlotStack set on request id=%d has=%s', $id, $has));
                } catch (\Throwable) {
                    \Agavi\Logging\Log::for($this)->debug('[SlotMW] SlotStack set (unable to introspect request id)');
                }
            }
            // Inform context about the request instance change so it stays in sync
            if ($this->context !== null) {
                try {
                    $this->context->setRequest($request);
                } catch (\Throwable) {
                }
            }
        } else {
            if (\Agavi\Logging\Log::for($this)->isEnabled(\Agavi\Logging\Level::Debug)) {
                try {
                    \Agavi\Logging\Log::for($this)->debug(sprintf('[SlotMW] SlotStack already present on request id=%d', spl_object_id($request)));
                } catch (\Throwable) {
                }
            }
        }
        return $handler->handle($request);
    }
}
