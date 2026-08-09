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

	/**
	 * Builds a rule for a map with dynamic string keys whose every value must
	 * match $value.
	 *
	 * Use this where the key set is data rather than schema -- a
	 * connection-name-keyed map of database entries, say -- and
	 * {@see self::struct()} where the keys are known up front. Non-string keys
	 * are reported; the keys themselves are otherwise unconstrained. Pass
	 * $nullable to also accept null in this position.
	 */
	public static function dictOf(Rule $value, bool $nullable = false): self
	{
		return new self(SchemaType::Dict, $nullable, items: $value);
	}

	/**
	 * Builds a rule for a sequential list whose every element must match $item.
	 *
	 * The value has to be a real list -- an array with contiguous integer keys
	 * from zero -- so a string-keyed map in this position is reported rather
	 * than accepted. Pass $nullable to also accept null.
	 */
	public static function listOf(Rule $item, bool $nullable = false): self
	{
		return new self(SchemaType::ListOf, $nullable, items: $item);
	}

	/**
	 * Builds a rule for a PHP string value, or null when $nullable is set.
	 *
	 * The check is on the PHP type only: numeric strings and the empty string
	 * both pass. Use {@see self::enumOf()} to restrict the value set and
	 * {@see self::phpClass()} for class-name strings.
	 */
	public static function string(bool $nullable = false): self
	{
		return new self(SchemaType::String, $nullable);
	}

	/**
	 * Builds a rule for a real PHP bool, or null when $nullable is set.
	 *
	 * Strings such as "true" and "on" do not pass; the canonical array is
	 * expected to have had such literals coerced by the config handler before
	 * it reaches schema validation.
	 */
	public static function bool(bool $nullable = false): self
	{
		return new self(SchemaType::Bool, $nullable);
	}

	/**
	 * Builds a rule for a real PHP int, or null when $nullable is set.
	 *
	 * Numeric strings and floats do not pass, so a value read straight from XML
	 * must have been cast by the config handler first.
	 */
	public static function int(bool $nullable = false): self
	{
		return new self(SchemaType::Int, $nullable);
	}

	/**
	 * Builds a rule for a non-empty string that is shaped like a PHP class name.
	 *
	 * Only the syntax is checked -- optional leading backslash, backslash-separated
	 * identifier segments -- because schema validation is pure and does not
	 * autoload. Whether the class exists is left to whoever instantiates it.
	 * Pass $nullable to also accept null.
	 */
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

	/**
	 * Builds a rule that accepts any value, including null.
	 *
	 * Nothing below this point is inspected, so it is how an open-ended region
	 * of the canonical array -- a free-form parameter bag, for instance -- is
	 * marked as deliberately unconstrained rather than left out of the schema.
	 * There is no $nullable argument because such a rule is always nullable.
	 */
	public static function mixed(): self
	{
		return new self(SchemaType::Mixed, true);
	}
}

?>
