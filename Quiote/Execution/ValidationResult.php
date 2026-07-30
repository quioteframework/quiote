<?php
namespace Quiote\Execution;

/**
 * Lightweight immutable validation result for container-less execution paths.
 */
class ValidationResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
    ) {}

    /**
     * @return array<int|string, mixed>
     */
    public function getErrors(): array
    {
        $errors = $this->data['errors'] ?? [];
        if (!is_array($errors)) {
            throw new \UnexpectedValueException(sprintf('ValidationResult "errors" entry must be an array, %s given.', get_debug_type($errors)));
        }
        return $errors;
    }

    public function getTrace(): ?ValidationTrace
    {
        $trace = $this->data['trace'] ?? null;
        if ($trace !== null && !$trace instanceof ValidationTrace) {
            throw new \UnexpectedValueException(sprintf('ValidationResult "trace" entry must be a ValidationTrace instance, %s given.', get_debug_type($trace)));
        }
        return $trace;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data = []): self { return new self(true, $data); }

    /**
     * @param array<string, mixed> $data
     */
    public static function failure(array $data = []): self { return new self(false, $data); }
}
