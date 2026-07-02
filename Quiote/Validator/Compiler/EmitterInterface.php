<?php
namespace Quiote\Validator\Compiler;

use Quiote\Validator\Compiler\Ir\ValidatorPlan;

/**
 * A back-end that turns a format-independent ValidatorPlan into a
 * committable/checkable PHP artifact (e.g. FluentSourceEmitter). This is
 * distinct from RuntimeArrayEmitter, which produces the raw snippet lines
 * ValidatorConfigHandler wraps into its own cache-file header at request
 * time -- that path has no need for the checksum/target-hint contract
 * emitters here are built around, since it's never diffed or committed.
 * @since      1.0.0
 */
interface EmitterInterface
{
	public function emit(ValidatorPlan $plan): EmittedArtifact;
}
