<?php
namespace Agavi\Execution;

/**
 * Immutable value object describing which action to execute.
 */
final class ActionDescriptor
{
    public function __construct(
        public readonly string $module,
        public readonly string $action,
        public readonly string $method,
        public readonly string $outputType,
        public readonly bool $isSimple
    ) {}

    /**
     * Build a descriptor by inspecting the action instance (authoritative isSimple flag).
     */
    public static function fromController(\Agavi\Controller\AgaviController $controller, string $module, string $action, string $method, string $outputType): self
    {
        $instance = $controller->createActionInstance($module, $action);
        $isSimple = method_exists($instance, 'isSimple') ? (bool)$instance->isSimple() : false;
        return new self($module, $action, $method, $outputType, $isSimple);
    }
}
