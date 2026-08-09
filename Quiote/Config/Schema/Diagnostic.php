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

	/**
	 * Builds a Diagnostic with Error severity, for a shape violation that makes
	 * the canonical array invalid.
	 *
	 * $code is a stable machine-readable identifier (SchemaValidator uses codes
	 * such as "schema.wrong_type" or "schema.missing_required_key"), $message the
	 * human-readable explanation, and $keyPath the dot-joined location the
	 * violation was found at ('' for the document root).
	 */
	public static function error(string $code, string $message, string $keyPath): self
	{
		return new self(Severity::Error, $code, $message, $keyPath);
	}

	/**
	 * Builds a Diagnostic with Warning severity, for a finding a caller may
	 * report but that does not on its own make the canonical array invalid.
	 *
	 * Takes the same machine-readable $code, human-readable $message and
	 * dot-joined $keyPath as {@see self::error()}; only the severity differs,
	 * so a caller can partition one diagnostic list into fatal and advisory.
	 */
	public static function warning(string $code, string $message, string $keyPath): self
	{
		return new self(Severity::Warning, $code, $message, $keyPath);
	}
}

?>
