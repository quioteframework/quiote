<?php

declare(strict_types=1);

namespace Quiote\Execution\Slot;

use Quiote\Logging\CategoryLogger;
use Quiote\Request\RequestState;
use Quiote\Request\WebRequest;

/**
 * Puts a slot's own parameters on the shared request for the length of its
 * dispatch, and takes them off again afterwards.
 *
 * A slot receives arguments, but the action it dispatches reads them from the
 * request like any other action -- so the arguments have to be visible there
 * and then stop being visible, or one slot's arguments leak into everything
 * rendered after it.
 *
 * What gets restored is what the request exposed at overlay time, which is the
 * *validated* request rather than what the client submitted: a parameter
 * validation pruned must stay pruned, since putting the submitted value back
 * would publish unvalidated input to the rest of the page.
 *
 * A name the parent did not have is revoked rather than blanked. setParameter()
 * adds a whitelist entry, and leaving the name declared would turn a later
 * getParameter() from a refusal into a silent null.
 *
 * Every restore failure is reported and the rest still attempted: each name left
 * behind is one the parent request now reads with this slot's value.
 */
final class SlotParameterOverlay
{
    /** @var array<string, array{present: bool, value: mixed}> */
    private array $originals = [];

    private bool $applied = false;

    public function __construct(
        private readonly \Quiote\Context $context,
        private readonly CategoryLogger $logger,
        private readonly string $slotKey,
    ) {}

    /**
     * Applies the slot's parameters, remembering what each name held before.
     *
     * @param array<string, mixed> $parameters
     * @return WebRequest The overlaid request, published to the context.
     * @throws \RuntimeException if there is no request to overlay onto.
     */
    public function apply(array $parameters): WebRequest
    {
        $request = $this->currentRequest();
        if (!$request instanceof WebRequest) {
            throw new \RuntimeException('Canonical WebRequest missing when applying slot parameters');
        }

        foreach ($parameters as $name => $value) {
            if (!array_key_exists($name, $this->originals)) {
                $present = $request->hasParameter($name);
                $this->originals[$name] = [
                    'present' => $present,
                    'value' => $present ? $request->getParameter($name, null) : null,
                ];
            }
            $request = $request->setParameter($name, $value);
        }

        $this->context->getContainer()->get(RequestState::class)->publish($request);
        $this->applied = true;

        if ($this->logger->isEnabled(\Quiote\Logging\Level::Debug)) {
            $this->logger->debugWith(
                fn(): string => '[SlotDisp] overlay_applied key=' . $this->slotKey
                    . ' params=' . json_encode($parameters, JSON_UNESCAPED_SLASHES)
            );
        }

        return $request;
    }

    public function wasApplied(): bool
    {
        return $this->applied;
    }

    /**
     * Puts the parent's parameters back and republishes the request.
     *
     * Safe to call when nothing was applied, so the caller can invoke it
     * unconditionally from a finally block.
     */
    public function restore(?WebRequest $request): void
    {
        if (!$this->applied || !$request instanceof WebRequest) {
            return;
        }

        foreach ($this->originals as $name => $original) {
            $request = $original['present']
                ? $this->restoreValue($request, (string) $name, $original['value'])
                : $this->revoke($request, (string) $name);
        }

        try {
            $this->context->getContainer()->get(RequestState::class)->publish($request);
        } catch (\Throwable $e) {
            // The restored request never reached the context, so everything after this slot
            // reads the overlaid one.
            $this->logger->error(
                '[SlotDisp] could not publish the restored request after slot ' . $this->slotKey
                . '; the parent request keeps the slot overlay: ' . $e->getMessage()
            );
        }
    }

    private function restoreValue(WebRequest $request, string $name, mixed $value): WebRequest
    {
        try {
            return $request->setParameter($name, $value);
        } catch (\Throwable $e) {
            // The parent's original value could not be put back, so the slot's value stands in
            // for it for the rest of the render.
            $this->logger->error(
                '[SlotDisp] could not restore parameter "' . $name . '" after slot ' . $this->slotKey
                . '; the parent request keeps the slot value: ' . $e->getMessage()
            );

            return $request;
        }
    }

    private function revoke(WebRequest $request, string $name): WebRequest
    {
        try {
            return $request->revokeParameter($name);
        } catch (\Throwable $e) {
            // A parameter the overlay introduced is still on the request, so the rest of the
            // page can read a value that belonged to this slot alone.
            $this->logger->error(
                '[SlotDisp] could not remove overlay parameter "' . $name . '" after slot '
                . $this->slotKey . '; it remains visible to the parent request: ' . $e->getMessage()
            );

            return $request;
        }
    }

    private function currentRequest(): ?WebRequest
    {
        try {
            return $this->context->getContainer()->get(WebRequest::class);
        } catch (\Throwable) {
            // No request to overlay onto; the caller reports that rather than this.
            return null;
        }
    }
}
