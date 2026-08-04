<?php

namespace Quiote;

use Quiote\Logging\CategoryLogger;
use Quiote\Logging\Level;

/**
 * The ordered set of clears that must happen at a worker request boundary, and the guarantee that
 * every one of them happens.
 *
 * The guarantee is the point. These steps drop the state that must not survive into the next
 * request served by the same process -- the session bag, the user, the request -- and a step that
 * throws must not prevent the steps after it from running. A half-cleared context that keeps
 * request N's authenticated user installed serves request N+1 as that user, which is a cross-user
 * authentication leak rather than a stale-data annoyance. So each step is independently guarded and
 * a failure is logged at error level and stepped over.
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
final class RequestBoundaryCleanup
{
    /**
     * @var        array<int, array{label: string, step: \Closure(): void}>
     */
    private array $steps = [];

    /**
     * Register a clear, to run after everything registered before it.
     *
     * @param      string $label Names the step in the debug line, and in the error line if it
     *             fails. It is the only thing that identifies which clear broke.
     * @param      \Closure(): void $step
     * @since      4.0.0
     */
    public function add(string $label, \Closure $step): void
    {
        $this->steps[] = ['label' => $label, 'step' => $step];
    }

    /**
     * Run every step, in order, guarded.
     *
     * Never throws: a caller running this from a `finally` needs it not to replace whatever
     * exception is already in flight.
     *
     * @since      4.0.0
     */
    public function run(CategoryLogger $logger): void
    {
        $debug = $logger->isEnabled(Level::Debug);

        foreach ($this->steps as $entry) {
            try {
                ($entry['step'])();
                if ($debug) {
                    $logger->debug('[RequestBoundaryCleanup] cleared ' . $entry['label']);
                }
            } catch (\Throwable $e) {
                $logger->error(sprintf(
                    '[RequestBoundaryCleanup] clearing %s failed, continuing with the rest: %s',
                    $entry['label'],
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * The registered step labels, in run order. For assertions about what a context clears, and
     * for diagnostics.
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
     * Forget every registered step. For a context being rebuilt, and for tests.
     *
     * @since      4.0.0
     */
    public function clear(): void
    {
        $this->steps = [];
    }
}
