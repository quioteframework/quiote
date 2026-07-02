<?php
// Fixture for CompiledValidatorRegistry/Action integration tests.
// Deliberately hand-written to also double as an example of the
// committable format FluentSourceEmitter produces -- there is no XML
// counterpart for this action at all.
return static function (\Quiote\Validator\Compiler\Runtime\ValidatorBuilder $v): void {
	$v->string('username', true)->minLength(3);
};
