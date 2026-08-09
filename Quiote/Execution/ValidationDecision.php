<?php
namespace Quiote\Execution;

/**
 * Immutable value object encapsulating the validation outcome for a request/action.
 * States:
 *  - pending: validation has not yet run (or was invalidated by a forward)
 *  - passed: validation executed successfully
 *  - failed: validation executed and failed (errors available)
 */
final readonly class ValidationDecision
{
    /**
     * @param array<mixed> $errors
     */
    private function __construct(
        public string $state, // 'pending' | 'passed' | 'failed'
        public array $errors = []
    ) {}

    /** Returns a decision in the pending state: validation has not run, and carries no errors. */
    public static function pending(): self { return new self('pending'); }
    /** Returns a decision in the passed state: validation ran successfully, and carries no errors. */
    public static function passed(): self { return new self('passed'); }

    /**
     * @param array<mixed> $errors
     */
    public static function failed(array $errors = []): self { return new self('failed', $errors); }

    /** Reports whether validation has not run yet, or was invalidated by a forward. */
    public function isPending(): bool { return $this->state === 'pending'; }
    /** Reports whether validation ran and succeeded. */
    public function isPassed(): bool { return $this->state === 'passed'; }
    /** Reports whether validation ran and failed; the errors are then on the $errors property. */
    public function isFailed(): bool { return $this->state === 'failed'; }
}
?>
