<?php
namespace Quiote\Validator\Compiler;

/**
 * The result of emitting a ValidatorPlan through a back-end: the PHP source
 * text, a checksum of it (for --check drift detection without writing
 * anything to disk), and a hint for where it would naturally be written.
 * Emitters never write files themselves -- ArtifactWriter (and eventually a
 * CLI) decides that.
 * @since      1.0.0
 */
final class EmittedArtifact
{
	public function __construct(
		public readonly string $phpSource,
		public readonly string $checksum,
		public readonly string $targetHint,
	) {
	}

	/**
	 * @param string $phpSource
	 * @param string $targetHint
	 */
	public static function fromSource(string $phpSource, string $targetHint): self
	{
		return new self($phpSource, hash('sha256', $phpSource), $targetHint);
	}
}
