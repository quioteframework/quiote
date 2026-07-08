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
    public function getErrors(): array { return $this->data['errors'] ?? []; }
    public function getTrace(): ?ValidationTrace { return $this->data['trace'] ?? null; }

    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data = []): self { return new self(true, $data); }

    /**
     * @param array<string, mixed> $data
     */
    public static function failure(array $data = []): self { return new self(false, $data); }
}
