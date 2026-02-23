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
     * Per-worker cache: class name → isSimple bool.
     * Safe to cache statically because isSimple() is a pure function of the class.
     *
     * @var array<string,bool>
     */
    private static array $isSimpleCache = [];

    /**
     * Build a descriptor by inspecting the action class (authoritative isSimple flag).
     *
     * The class is instantiated once per unique class name per worker lifetime;
     * subsequent calls for the same module:action pair read from the static cache.
     */
    public static function fromController(\Agavi\Controller\AgaviController $controller, string $module, string $action, string $method, string $outputType): self
    {
        $cacheKey = $module . ':' . $action;
        if (!isset(self::$isSimpleCache[$cacheKey])) {
            $instance = $controller->createActionInstance($module, $action);
            self::$isSimpleCache[$cacheKey] = method_exists($instance, 'isSimple') ? (bool)$instance->isSimple() : false;
        }
        return new self($module, $action, $method, $outputType, self::$isSimpleCache[$cacheKey]);
    }
}
