<?php
namespace Quiote\Config\Format;

/**
 * Format-agnostic parent/child config inheritance (phase 4). Every
 * FormatDriver resolves its own `parent` chain the same
 * way: load the parent's array first, then deep-merge this file's array on
 * top of it via array_replace_recursive() semantics -- child values win,
 * nested arrays merge key-by-key rather than replacing wholesale.
 *
 * This mirrors XmlConfigParser's own parent-chain resolution (parent files
 * loaded first, in reverse order, then merged), just operating on plain
 * arrays instead of DOM documents so the same merge logic works
 * regardless of which FormatDriver produced either side.
 * @since      1.0.0
 */
final class ArrayMergeStrategy
{
	/**
	 * Deep-merges $override onto $base. Scalar/list values in $override
	 * replace the corresponding value in $base outright; associative
	 * arrays present on both sides are merged recursively so a child
	 * config can override a single nested key without having to repeat
	 * its siblings.
	 * @template TKey of array-key
	 * @param array<TKey, mixed> $base
	 * @param array<TKey, mixed> $override
	 * @return array<TKey, mixed> The merged result. Neither input is mutated.
	 */
	public function merge(array $base, array $override): array
	{
		foreach ($override as $key => $value) {
			if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && $this->isAssociative($value) && $this->isAssociative($base[$key])) {
				$base[$key] = $this->merge($base[$key], $value);
			} else {
				$base[$key] = $value;
			}
		}
		return $base;
	}

	/**
	 * A list (sequential 0-based integer keys) is treated as a scalar unit
	 * and replaced wholesale rather than merged index-by-index -- merging
	 * lists by position is rarely what a config author wants (it produces
	 * a length-dependent Frankenstein array), whereas replacing the whole
	 * list is predictable and matches how XML `parent` overrides already
	 * behave for repeated sibling elements.
	 * @param array<int|string, mixed> $value
	 */
	private function isAssociative(array $value): bool
	{
		return !array_is_list($value);
	}
}
