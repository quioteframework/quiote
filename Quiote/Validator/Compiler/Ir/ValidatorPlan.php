<?php
namespace Quiote\Validator\Compiler\Ir;

/**
 * Format-independent description of one validator config source (today:
 * one validators.xml file, after XInclude/XSL normalization). A ValidatorPlan
 * is what any back-end emitter (runtime cache, fluent source, a future
 * CLI's --check) consumes; it never needs to know which front-end produced it.
 * @since      1.0.0
 */
final class ValidatorPlan
{
	/**
	 * @param ValidatorNode[] $nodes Top-level validator nodes, in document order.
	 * @param string $sourceRef Origin reference (e.g. file path), for diagnostics
	 *                          and generated-file headers.
	 */
	public function __construct(
		public readonly array $nodes,
		public readonly string $sourceRef,
	) {
	}
}
