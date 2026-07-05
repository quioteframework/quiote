<?php
namespace Quiote\Validator\Compiler\Ir;

/**
 * Format-independent description of a single <validator> declaration:
 * the resolved class, its parameters/arguments, the request methods it
 * applies to, and any nested validators (and/or/not/xor children).
 *
 * This is the intermediate representation shared by every front-end
 * (currently only the XML parser) and every back-end (the runtime
 * cache emitter, the fluent-source emitter). Its shape is a snapshot of
 * what ValidatorConfigHandler used to compute and immediately discard
 * while emitting PHP text directly from the DOM walk.
 * @since      1.0.0
 */
final class ValidatorNode
{
	/**
	 * @param string $name The validator's name (explicit or generated).
	 * @param string $validatorClass The resolved, fully-qualified validator class.
	 * @param array<int, mixed> $arguments Request parameter names/sub-paths this validator reads.
	 * @param string $base The base path from <arguments base="...">, or ''.
	 * @param array<string, mixed> $parameters The fully resolved, already-checked parameter bag.
	 * @param array<int|string, mixed> $errors Error message overrides, keyed by error index (or '').
	 * @param string[] $methods The HTTP methods this validator applies to (or [''] for all).
	 * @param string[] $declaredNames Request parameter names this validator whitelists.
	 * @param ValidatorNode[] $children Nested validators (and/or/not/xor containers).
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $validatorClass,
		public readonly array $arguments,
		public readonly string $base,
		public readonly array $parameters,
		public readonly array $errors,
		public readonly array $methods,
		public readonly array $declaredNames,
		public readonly array $children = [],
	) {
	}
}
