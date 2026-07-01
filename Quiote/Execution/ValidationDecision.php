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
    private function __construct(
        public string $state, // 'pending' | 'passed' | 'failed'
        public array $errors = []
    ) {}

    public static function pending(): self { return new self('pending'); }
    public static function passed(): self { return new self('passed'); }
    public static function failed(array $errors = []): self { return new self('failed', $errors); }

    public function isPending(): bool { return $this->state === 'pending'; }
    public function isPassed(): bool { return $this->state === 'passed'; }
    public function isFailed(): bool { return $this->state === 'failed'; }
}
?>
