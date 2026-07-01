<?php
namespace Quiote\Execution;

/**
 * Lightweight immutable validation result for container-less execution paths.
 */
class ValidationResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
    ) {}

    public function getErrors(): array { return $this->data['errors'] ?? []; }
    public function getTrace(): ?object { return $this->data['trace'] ?? null; }

    public static function success(array $data = []): self { return new self(true, $data); }
    public static function failure(array $data = []): self { return new self(false, $data); }
}
