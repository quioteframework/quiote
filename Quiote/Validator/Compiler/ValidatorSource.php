<?php
namespace Quiote\Validator\Compiler;

/**
 * A discovered (or explicitly given) validators.xml file, ready to be
 * parsed into a ValidatorPlan.
 * @since      1.0.0
 */
final class ValidatorSource
{
	public function __construct(
		public readonly string $path,
		public readonly ?string $environment = null,
	) {
	}
}
