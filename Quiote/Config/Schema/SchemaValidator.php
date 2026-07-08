<?php
namespace Quiote\Config\Schema;

/**
 * Validates a canonical config array against a declarative Rule shape.
 * Pure and stateless -- no I/O, no coupling to Config/ConfigCache -- so a
 * future validate_config probe capability can call it directly against an
 * already-loaded canonical array without threading through the config
 * cache pipeline again.
 * @since      1.0.0
 */
final class SchemaValidator
{
	private const PHP_CLASS_PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

	/**
	 * @return list<Diagnostic>
	 */
	public static function validate(Rule $schema, mixed $value, string $path = ''): array
	{
		if ($value === null) {
			if ($schema->nullable) {
				return [];
			}
			return [Diagnostic::error('schema.null_not_allowed', self::at($path, 'must not be null'), $path)];
		}

		return match ($schema->type) {
			SchemaType::Mixed => [],
			SchemaType::String => self::validateScalar($value, 'is_string', 'string', $path),
			SchemaType::Bool => self::validateScalar($value, 'is_bool', 'bool', $path),
			SchemaType::Int => self::validateScalar($value, 'is_int', 'int', $path),
			SchemaType::PhpClass => self::validatePhpClass($value, $path),
			SchemaType::Enum => self::validateEnum($schema, $value, $path),
			SchemaType::ListOf => self::validateList($schema, $value, $path),
			SchemaType::Dict => self::validateDict($schema, $value, $path),
			SchemaType::Struct => self::validateStruct($schema, $value, $path),
		};
	}

	/**
	 * @return list<Diagnostic>
	 */
	private static function validateScalar(mixed $value, callable $check, string $typeName, string $path): array
	{
		if (!$check($value)) {
			return [Diagnostic::error('schema.wrong_type', self::at($path, "must be $typeName, got " . get_debug_type($value)), $path)];
		}
		return [];
	}

	/**
	 * @return list<Diagnostic>
	 */
	private static function validatePhpClass(mixed $value, string $path): array
	{
		if (!is_string($value) || $value === '' || !preg_match(self::PHP_CLASS_PATTERN, $value)) {
			return [Diagnostic::error('schema.invalid_php_class', self::at($path, 'must be a valid PHP class-name string'), $path)];
		}
		return [];
	}

	/**
	 * @return list<Diagnostic>
	 */
	private static function validateEnum(Rule $schema, mixed $value, string $path): array
	{
		if (!is_string($value) || !in_array($value, $schema->enumValues, true)) {
			$allowed = implode(', ', array_map(static fn(string $v): string => "\"$v\"", $schema->enumValues));
			return [Diagnostic::error('schema.invalid_enum_value', self::at($path, "must be one of: $allowed"), $path)];
		}
		return [];
	}

	/**
	 * @return list<Diagnostic>
	 */
	private static function validateList(Rule $schema, mixed $value, string $path): array
	{
		if (!is_array($value) || !array_is_list($value)) {
			return [Diagnostic::error('schema.wrong_type', self::at($path, 'must be a list'), $path)];
		}

		$diagnostics = [];
		/** @var Rule $items */
		$items = $schema->items;
		foreach ($value as $index => $item) {
			$diagnostics = [...$diagnostics, ...self::validate($items, $item, "{$path}[{$index}]")];
		}
		return $diagnostics;
	}

	/**
	 * @return list<Diagnostic>
	 */
	private static function validateDict(Rule $schema, mixed $value, string $path): array
	{
		if (!is_array($value)) {
			return [Diagnostic::error('schema.wrong_type', self::at($path, 'must be a map'), $path)];
		}

		$diagnostics = [];
		/** @var Rule $items */
		$items = $schema->items;
		foreach ($value as $key => $item) {
			if (!is_string($key)) {
				$diagnostics[] = Diagnostic::error('schema.wrong_type', self::at($path, "must have string keys, got integer index $key"), $path);
				continue;
			}
			$childPath = self::join($path, $key);
			$diagnostics = [...$diagnostics, ...self::validate($items, $item, $childPath)];
		}
		return $diagnostics;
	}

	/**
	 * @return list<Diagnostic>
	 */
	private static function validateStruct(Rule $schema, mixed $value, string $path): array
	{
		if (!is_array($value)) {
			return [Diagnostic::error('schema.wrong_type', self::at($path, 'must be a map'), $path)];
		}

		$diagnostics = [];

		foreach ($schema->required as $key) {
			if (!array_key_exists($key, $value)) {
				$diagnostics[] = Diagnostic::error('schema.missing_required_key', self::at(self::join($path, $key), 'is required'), self::join($path, $key));
			}
		}

		foreach ($value as $key => $child) {
			if (!is_string($key)) {
				$diagnostics[] = Diagnostic::error('schema.wrong_type', self::at($path, "must have string keys, got integer index $key"), $path);
				continue;
			}
			$childPath = self::join($path, $key);
			if (!isset($schema->keys[$key])) {
				if ($schema->closed) {
					$diagnostics[] = Diagnostic::error('schema.unknown_key', self::at($childPath, 'is not a recognized key'), $childPath);
				}
				continue;
			}
			$diagnostics = [...$diagnostics, ...self::validate($schema->keys[$key], $child, $childPath)];
		}

		return $diagnostics;
	}

	private static function join(string $path, string $key): string
	{
		return $path === '' ? $key : "$path.$key";
	}

	private static function at(string $path, string $rest): string
	{
		$label = $path === '' ? '(root)' : $path;
		return "\"$label\" $rest";
	}
}

?>
