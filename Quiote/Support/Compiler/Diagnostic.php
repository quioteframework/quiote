<?php
namespace Quiote\Support\Compiler;

/**
 * A single problem or note surfaced while building a ValidatorPlan or
 * emitting from one. Diagnostics let a caller (a future CLI, a test, a
 * warn-mode compile) see every issue in a source, instead of only the
 * first one that happened to abort a throw-mode build.
 * @since      1.0.0
 */
final class Diagnostic
{
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_ERROR = 'error';

	public const CODE_UNKNOWN_PARAMETER = 'UNKNOWN_PARAMETER';
	public const CODE_UNRESOLVABLE_CLASS = 'UNRESOLVABLE_CLASS';
	public const CODE_UNMAPPABLE_PARAMETER = 'UNMAPPABLE_PARAMETER';
	public const CODE_SHADOWED_CONFIG = 'SHADOWED_CONFIG';
	public const CODE_MISSING_ACTION_CLASS = 'MISSING_ACTION_CLASS';
	public const CODE_MISSING_VIEW = 'MISSING_VIEW';
	public const CODE_MISSING_TEMPLATE = 'MISSING_TEMPLATE';
	public const CODE_MISSING_VALIDATOR = 'MISSING_VALIDATOR';

	public function __construct(
		public readonly string $severity,
		public readonly string $code,
		public readonly string $message,
		public readonly string $where,
		public readonly ?int $line = null,
		public readonly ?int $column = null,
		public readonly ?int $endLine = null,
		public readonly ?int $endColumn = null,
		public readonly ?string $symbol = null,
	) {
	}

	/**
	 * JSON-ready shape shared by every console/probe consumer that surfaces
	 * this Diagnostic, so each one doesn't hand-roll its own field mapping
	 * (and possibly its own field names) from `where` to `file`.
	 * @return array{severity: string, code: string, message: string, file: string, line: ?int, column: ?int, endLine: ?int, endColumn: ?int, symbol: ?string}
	 */
	public function toArray(): array
	{
		return [
			'severity' => $this->severity,
			'code' => $this->code,
			'message' => $this->message,
			'file' => $this->where,
			'line' => $this->line,
			'column' => $this->column,
			'endLine' => $this->endLine,
			'endColumn' => $this->endColumn,
			'symbol' => $this->symbol,
		];
	}
}
