<?php
namespace Quiote\Config\Schema;

/**
 * One structural-shape violation found by SchemaValidator. $keyPath is
 * dot-joined (e.g. "databases.default_db.class") so callers -- including
 * a future probe capability -- can report it against the canonical array
 * without any further formatting.
 * @since      1.0.0
 */
final readonly class Diagnostic
{
	private function __construct(
		public Severity $severity,
		public string $code,
		public string $message,
		public string $keyPath,
	) {
	}

	public static function error(string $code, string $message, string $keyPath): self
	{
		return new self(Severity::Error, $code, $message, $keyPath);
	}

	public static function warning(string $code, string $message, string $keyPath): self
	{
		return new self(Severity::Warning, $code, $message, $keyPath);
	}
}

?>
