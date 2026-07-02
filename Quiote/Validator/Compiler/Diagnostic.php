<?php
namespace Quiote\Validator\Compiler;

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

	public function __construct(
		public readonly string $severity,
		public readonly string $code,
		public readonly string $message,
		public readonly string $where,
	) {
	}
}
