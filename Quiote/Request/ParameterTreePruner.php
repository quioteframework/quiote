<?php

declare(strict_types=1);

namespace Quiote\Request;

use Quiote\Util\ArrayPathDefinition;

/**
 * Prunes the inside of array-valued request parameters down to the paths
 * validation actually vouched for.
 *
 * Built from the bracket paths of the arguments that succeeded and failed
 * validation (`rows[0][ItemName]`, `items[]`, `user`). Inside a root
 * parameter:
 *
 * - a node survives when a kept path names it exactly, or when it is an
 *   ancestor of one. An ancestor survives as a container only: its other
 *   children still need paths of their own. A kept path that ends on an array
 *   keeps that array as a container too, so a validator on `rows` (an
 *   emptiness or length check) does not vouch for the posted rows inside it;
 * - a node named by a failed path is removed with everything under it, and
 *   that beats every kept path;
 * - an array left with no surviving children is removed, unless a kept path
 *   names it;
 * - an empty segment (`items[]`) matches every key at that level.
 *
 * Deciding whether the root parameter itself survives stays with the caller;
 * {@see roots()} lists the roots any kept path reaches.
 */
final class ParameterTreePruner
{
    private ParameterTreeNode $keepTrie;

    private ParameterTreeNode $failedTrie;

    /**
     * @param iterable<string> $keep Paths of arguments that succeeded.
     * @param iterable<string> $failed Paths of arguments that failed.
     */
    public function __construct(iterable $keep, iterable $failed)
    {
        $this->keepTrie = new ParameterTreeNode();
        $this->failedTrie = new ParameterTreeNode();
        foreach ($keep as $path) {
            self::insert($this->keepTrie, $path);
        }
        foreach ($failed as $path) {
            self::insert($this->failedTrie, $path);
        }
    }

    /**
     * The root parameter names that at least one kept path reaches, whether it
     * names the root itself or something inside it.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        return array_map('strval', array_keys($this->keepTrie->children));
    }

    /**
     * Returns the value of root parameter $root with every node validation did
     * not vouch for removed. The returned array is the root's container, which
     * stays even when nothing inside it survived; whether the root parameter
     * itself is kept is the caller's decision.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public function prune(string $root, array $value): array
    {
        $keepNodes = isset($this->keepTrie->children[$root]) ? [$this->keepTrie->children[$root]] : [];
        $failedNodes = isset($this->failedTrie->children[$root]) ? [$this->failedTrie->children[$root]] : [];

        return self::pruneLevel($value, $keepNodes, $failedNodes);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<ParameterTreeNode> $keepNodes
     * @param list<ParameterTreeNode> $failedNodes
     * @return array<array-key, mixed>
     */
    private static function pruneLevel(array $value, array $keepNodes, array $failedNodes): array
    {
        $pruned = [];
        foreach ($value as $key => $child) {
            $segment = (string) $key;
            $failedChildren = self::childNodes($failedNodes, $segment);
            if (self::anyNamed($failedChildren)) {
                continue;
            }
            $keepChildren = self::childNodes($keepNodes, $segment);
            if ($keepChildren === []) {
                continue;
            }
            $named = self::anyNamed($keepChildren);
            if (is_array($child)) {
                $inner = self::pruneLevel($child, $keepChildren, $failedChildren);
                if ($inner !== [] || $named) {
                    $pruned[$key] = $inner;
                }
            } elseif ($named) {
                $pruned[$key] = $child;
            }
        }

        return $pruned;
    }

    /**
     * The trie nodes one level below $nodes that match $segment, counting the
     * empty-segment wildcard.
     *
     * @param list<ParameterTreeNode> $nodes
     * @return list<ParameterTreeNode>
     */
    private static function childNodes(array $nodes, string $segment): array
    {
        $matches = [];
        foreach ($nodes as $node) {
            foreach ($segment === '' ? [''] : [$segment, ''] as $candidate) {
                if (isset($node->children[$candidate])) {
                    $matches[] = $node->children[$candidate];
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<ParameterTreeNode> $nodes
     */
    private static function anyNamed(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node->named) {
                return true;
            }
        }

        return false;
    }

    private static function insert(ParameterTreeNode $trie, string $path): void
    {
        $segments = self::segments($path);
        if ($segments === null) {
            return;
        }
        $node = $trie;
        foreach ($segments as $segment) {
            $node = $node->child($segment);
        }
        $node->named = true;
    }

    /**
     * @return non-empty-list<string>|null Null for a path that has no root
     *         parameter name (relative or empty) or cannot be parsed.
     */
    private static function segments(string $path): ?array
    {
        try {
            $info = ArrayPathDefinition::getPartsFromPath($path);
        } catch (\InvalidArgumentException $e) {
            \Quiote\Logging\Log::create(self::class)->debug(
                '[ParameterTreePruner] ignoring unparsable path "' . $path . '": ' . $e->getMessage()
            );
            return null;
        }
        if (!$info['absolute'] || $info['parts'] === [] || (string) $info['parts'][0] === '') {
            return null;
        }

        return array_values($info['parts']);
    }
}
