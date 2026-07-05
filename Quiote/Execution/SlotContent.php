<?php
namespace Quiote\Execution;

/**
 * Immutable value object representing rendered slot content plus metadata.
 * This is the migration target replacing ad hoc ExecutionContainer based
 * slot handling. It intentionally carries only the data needed by template
 * layers and renderers; heavy lifecycle & parameter APIs stay with the legacy
 * container path until fully removed.
 */
final readonly class SlotContent implements SlotRenderable, \Stringable
{
    // original argument hash (already merged / sanitized)

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(private string $module, private string $action, private ?string $outputType, private string $content, private array $arguments = [])
    {
    }

    public function getModule(): string { return $this->module; }
    public function getAction(): string { return $this->action; }
    public function getOutputType(): ?string { return $this->outputType; }

    /** @return array<string, mixed> */
    public function getArguments(): array { return $this->arguments; }

    /** Return the already rendered slot content. */
    public function getContent(): string { return $this->content; }

    public function __toString(): string { return $this->content; }

    /**
     * @return array{module: string, action: string, output_type: ?string, arguments: array<string, mixed>, content_length: int}
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'action' => $this->action,
            'output_type' => $this->outputType,
            'arguments' => $this->arguments,
            'content_length' => strlen($this->content),
        ];
    }
}
