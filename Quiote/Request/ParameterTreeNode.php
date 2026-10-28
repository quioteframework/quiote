<?php

declare(strict_types=1);

namespace Quiote\Request;

/**
 * One segment of a request-parameter path in a {@see ParameterTreePruner}
 * trie: whether a path ends here, and the segments that continue below it.
 *
 * @internal
 */
final class ParameterTreeNode
{
    /** Whether a path names this node itself. */
    public bool $named = false;

    /** @var array<string, ParameterTreeNode> */
    public array $children = [];

    /**
     * Returns the child for $segment, creating it when it does not exist yet.
     */
    public function child(string $segment): self
    {
        return $this->children[$segment] ??= new self();
    }
}
