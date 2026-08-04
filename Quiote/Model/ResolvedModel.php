<?php

namespace Quiote\Model;

/**
 * What {@see ModelClassResolver} learned about a model name: which class it names, and the
 * two facts about that class the instantiation path needs.
 *
 * Both flags come from reflection, which is why they are resolved once and cached with the
 * class name rather than probed per call.
 *
 * @since      4.0.0
 */
final readonly class ResolvedModel
{
    /**
     * @param      class-string $class The class the model name resolved to.
     * @param      bool $isSingleton Whether the class implements {@see ISingletonModel}, and
     *             so must be answered from the locator's per-context instance cache.
     * @param      bool $hasConstructor Whether the class declares a constructor. Without one,
     *             parameters go to initialize() only -- passing them to `new` would fail.
     */
    public function __construct(
        public string $class,
        public bool $isSingleton,
        public bool $hasConstructor,
    ) {}
}
