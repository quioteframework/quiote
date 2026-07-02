<?php
namespace Quiote\Validator\Compiler;

use Quiote\Support\Compiler\Diagnostic;
use Quiote\Support\Compiler\EmittedArtifact;

/**
 * The outcome of compiling one ValidatorSource through an emitter: the
 * artifact (null if a fatal diagnostic prevented emission) plus every
 * diagnostic recorded along the way. A future CLI reports diagnostics and
 * decides the process exit code from this; ValidatorCompiler itself never
 * throws for ordinary (non-crashing) problems in 'warn' mode.
 * @since      1.0.0
 */
final class CompilationResult
{
	/**
	 * @param Diagnostic[] $diagnostics
	 */
	public function __construct(
		public readonly ?EmittedArtifact $artifact,
		public readonly array $diagnostics,
	) {
	}

	public function hasErrors(): bool
	{
		foreach ($this->diagnostics as $diagnostic) {
			if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
				return true;
			}
		}
		return false;
	}
}
