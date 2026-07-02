<?php
namespace Quiote\Support\Compiler;

/**
 * The result of comparing a freshly emitted artifact against whatever is
 * (or isn't) already on disk at its target path, without writing anything.
 * @since      1.0.0
 */
final class ArtifactDriftResult
{
	public function __construct(
		public readonly bool $matches,
		public readonly ?string $existingChecksum,
		public readonly string $expectedChecksum,
		public readonly string $target,
	) {
	}
}
