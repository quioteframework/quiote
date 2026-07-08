<?php
namespace Quiote\Execution;

/**
 * @deprecated ViewResolver has been removed. Use ViewNameResolver directly.
 * This stub will be removed in a future hard-removal cleanup. Instantiation triggers a deprecation warning.
 */
class ViewResolver
{
    private readonly ViewNameResolver $delegate;
    public function __construct(?ViewNameResolver $delegate = null)
    {
        @trigger_error('ViewResolver is deprecated; use ViewNameResolver directly', E_USER_DEPRECATED);
        $this->delegate = $delegate ?? new ViewNameResolver();
    }
    /**
     * @return array{0: string|null, 1: string|null}
     */
    public function resolve(string $module, string $action, mixed $rawViewName): array
    {
        return $this->delegate->resolve($module,$action,$rawViewName);
    }
}
