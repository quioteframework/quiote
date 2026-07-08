<?php
namespace Quiote\Config\Schema;

/**
 * A declarative description of one canonical-array shape, structural only
 * (allowed keys, enums-of-kind, nesting) -- not required-ness that depends
 * on runtime state or document processing order, which stays a Layer-2
 * semantic check in the handler's own executeArray()/toCanonicalArray().
 *
 * $closed on a Struct means an unrecognized key is a diagnostic rather than
 * silently ignored, matching the XSDs' closed-content-model default.
 * @since      1.0.0
 */
final readonly class Rule
{
	/**
	 * @param array<string, Rule> $keys Struct only: known key => its Rule.
	 * @param list<string> $required Struct only: keys from $keys that must be present.
	 * @param list<string> $enumValues Enum only: the allowed string values.
	 */
	private function __construct(
		public SchemaType $type,
		public bool $nullable = false,
		public array $keys = [],
		public array $required = [],
		public bool $closed = true,
		public ?Rule $items = null,
		public array $enumValues = [],
	) {
	}

	/**
	 * @param array<string, Rule> $keys
	 * @param list<string> $required
	 */
	public static function struct(array $keys, array $required = [], bool $closed = true, bool $nullable = false): self
	{
		return new self(SchemaType::Struct, $nullable, $keys, $required, $closed);
	}

	public static function dictOf(Rule $value, bool $nullable = false): self
	{
		return new self(SchemaType::Dict, $nullable, items: $value);
	}

	public static function listOf(Rule $item, bool $nullable = false): self
	{
		return new self(SchemaType::ListOf, $nullable, items: $item);
	}

	public static function string(bool $nullable = false): self
	{
		return new self(SchemaType::String, $nullable);
	}

	public static function bool(bool $nullable = false): self
	{
		return new self(SchemaType::Bool, $nullable);
	}

	public static function int(bool $nullable = false): self
	{
		return new self(SchemaType::Int, $nullable);
	}

	public static function phpClass(bool $nullable = false): self
	{
		return new self(SchemaType::PhpClass, $nullable);
	}

	/**
	 * @param list<string> $values
	 */
	public static function enumOf(array $values, bool $nullable = false): self
	{
		return new self(SchemaType::Enum, $nullable, enumValues: $values);
	}

	public static function mixed(): self
	{
		return new self(SchemaType::Mixed, true);
	}
}

?>
