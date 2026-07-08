<?php

namespace Quiote\Controller;

/**
 * Contract required of an object set via
 * {@see ExecutionContainer::setRequestData()} for non-simple actions:
 * ExecutionContainer::initRequestData() clones it and, if additional
 * arguments were supplied, merges them into the clone. Typing the holder to
 * this interface (rather than a bare object) makes that requirement
 * explicit and checkable instead of only surfacing as an undefined-method
 * error at runtime.
 */
interface MergeableRequestDataHolder
{
    /**
     * Merge additional request arguments into this holder, in place.
     * @param array<string, mixed> $arguments
     */
    public function merge(array $arguments): void;
}
