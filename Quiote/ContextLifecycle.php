<?php

namespace Quiote;

use Quiote\Logging\CategoryLogger;
use Quiote\Logging\Level;

/**
 * A context's per-request state machine: armed, claimed, cleared, armed again.
 *
 * Three things that only make sense together:
 *
 * - **Arming.** A request begins, and the state flush for it has not run yet.
 * - **The flush claim.** Exactly one caller per request persists the request-scoped state that
 *   lives in the session. The session middleware claims it on the pipeline unwind, while the
 *   response has not been emitted and the session can still be written; the request boundary and
 *   shutdown claim it as a backstop for requests that never reached the middleware. The first
 *   caller wins, and the rest are no-ops rather than double writes.
 * - **The clears.** At the end of the request, everything that must not survive into the next
 *   request served by the same process is dropped.
 *
 * The clears carry the guarantee this class mostly exists for. They drop the session bag, the user
 * and the request, and a step that throws must not prevent the steps after it from running: a
 * half-cleared context that keeps request N's authenticated user installed serves request N+1 as
 * that user, which is a cross-user authentication leak rather than a stale-data annoyance. So each
 * step is independently guarded, and a failure is logged at error level and stepped over.
 *
 * Steps run in registration order, and the order is meaningful: most dangerous first. The context
 * registers the identity clears before anything that can fail.
 *
 * Also an extension seam. Anything holding request-scoped state of its own -- a plugin with a
 * per-request cache -- can register a clear here instead of having no way to hook the boundary at
 * all.
 *
 * @since      4.0.0
 */
final class ContextLifecycle
{
    /**
     * @var        array<int, array{label: string, step: \Closure(): void}>
     */
    private array $steps = [];

    /**
     * @var        bool True once this request's state flush has been claimed. Armed by
     *             {@see beginRequest()} and re-armed at the end of {@see endRequest()}.
     */
    private bool $requestStateFlushed = false;

    /**
     * Arm this context for a new request, so the flush for it can still be claimed.
     *
     * The authoritative anchor, called on the way in. {@see endRequest()} re-arms too, on the way
     * out, and this covers a runtime that serves requests without ending one between them.
     *
     * @since      4.0.0
     */
    public function beginRequest(): void
    {
        $this->requestStateFlushed = false;
    }

    /**
     * Claim this request's state flush, answering whether the caller won.
     *
     * True exactly once per request. Every later caller gets false and must do nothing: the state
     * has already been persisted, and persisting it again would write it twice -- or, after the
     * response has been emitted, write it somewhere nothing will ever read.
     *
     * @since      4.0.0
     */
    public function claimRequestStateFlush(): bool
    {
        if ($this->requestStateFlushed) {
            return false;
        }

        return $this->requestStateFlushed = true;
    }

    /**
     * Whether this request's state flush has been claimed.
     *
     * @since      4.0.0
     */
    public function requestStateFlushClaimed(): bool
    {
        return $this->requestStateFlushed;
    }

    /**
     * Register a clear to run when the request ends, after everything registered before it.
     *
     * @param      string $label Names the step in the debug line, and in the error line if it
     *             fails. It is the only thing that identifies which clear broke.
     * @param      \Closure(): void $step
     * @since      4.0.0
     */
    public function onRequestEnd(string $label, \Closure $step): void
    {
        $this->steps[] = ['label' => $label, 'step' => $step];
    }

    /**
     * End the request: run every clear, in order, guarded, then re-arm for the next one.
     *
     * Never throws. The context calls this from a `finally`, where throwing would replace whatever
     * exception caused the reset to fail in the first place.
     *
     * The re-arm happens last, after every clear, so each of them still sees this request's flush as
     * claimed -- a clear that consulted it would otherwise see a fresh request that has not
     * happened.
     *
     * @since      4.0.0
     */
    public function endRequest(CategoryLogger $logger): void
    {
        $debug = $logger->isEnabled(Level::Debug);

        foreach ($this->steps as $entry) {
            try {
                ($entry['step'])();
                if ($debug) {
                    $logger->debug('[ContextLifecycle] cleared ' . $entry['label']);
                }
            } catch (\Throwable $e) {
                $logger->error(sprintf(
                    '[ContextLifecycle] clearing %s failed, continuing with the rest: %s',
                    $entry['label'],
                    $e->getMessage(),
                ));
            }
        }

        $this->beginRequest();
    }

    /**
     * The registered step labels, in run order. For assertions about what a context clears, and for
     * diagnostics.
     *
     * @return     array<int, string>
     * @since      4.0.0
     */
    public function labels(): array
    {
        return array_map(
            static fn(array $entry): string => $entry['label'],
            $this->steps,
        );
    }

    /**
     * Forget every registered clear. For a context being re-initialized, and for tests.
     *
     * @since      4.0.0
     */
    public function forgetSteps(): void
    {
        $this->steps = [];
    }
}
