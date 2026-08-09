<?php
namespace Quiote\Validator\Compiler;

use Quiote\Support\Compiler\EmittedArtifact;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;

/**
 * A back-end that turns a format-independent ValidatorPlan into a
 * committable/checkable PHP artifact (e.g. FluentSourceEmitter). This is
 * distinct from RuntimeDeclarationEmitter, which produces the declaration
 * ValidatorConfigHandler wraps into its own cache-file header at request
 * time -- that path has no need for the checksum/target-hint contract
 * emitters here are built around, since it's never diffed or committed.
 * @since      1.0.0
 */
interface EmitterInterface
{
	/**
	 * Turns a validator plan into a PHP artifact.
	 *
	 * Implementations must be pure with respect to the filesystem: the
	 * artifact is returned, never written, so the caller decides between
	 * committing it and diffing it against what is already on disk. The same
	 * plan must always produce the same artifact, since the checksum carried
	 * on the artifact is what makes a --check comparison meaningful.
	 */
	public function emit(ValidatorPlan $plan): EmittedArtifact;
}
