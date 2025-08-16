<?php
namespace Agavi\Execution;

/**
 * Immutable value object representing rendered slot content plus metadata.
 *
 * This is the migration target replacing ad hoc AgaviExecutionContainer based
 * slot handling. It intentionally carries only the data needed by template
 * layers and renderers; heavy lifecycle & parameter APIs stay with the legacy
 * container path until fully removed.
 */
final class SlotContent implements SlotRenderable
{
    private string $module;
    private string $action;
    private ?string $outputType; // null means inherit
    private string $content;
    private array $arguments; // original argument hash (already merged / sanitized)

    public function __construct(string $module, string $action, ?string $outputType, string $content, array $arguments = [])
    {
        $this->module = $module;
        $this->action = $action;
        $this->outputType = $outputType;
        $this->content = $content;
        $this->arguments = $arguments;
    }

    public function getModule(): string { return $this->module; }
    public function getAction(): string { return $this->action; }
    public function getOutputType(): ?string { return $this->outputType; }
    public function getArguments(): array { return $this->arguments; }

    /** Return the already rendered slot content. */
    public function getContent(): string { return $this->content; }

    public function __toString(): string { return $this->content; }

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
