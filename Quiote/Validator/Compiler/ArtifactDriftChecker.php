<?php
namespace Quiote\Validator\Compiler;

/**
 * gofmt-style drift check: does the committed file at $target already
 * match what we'd emit right now? Never writes anything -- a future CLI's
 * `--check` mode is exactly "emit, checkDrift, exit non-zero on mismatch".
 * @since      1.0.0
 */
final class ArtifactDriftChecker
{
	public function check(EmittedArtifact $artifact, string $target): ArtifactDriftResult
	{
		if (!is_file($target)) {
			return new ArtifactDriftResult(false, null, $artifact->checksum, $target);
		}

		$existing = file_get_contents($target);
		$existingChecksum = $existing === false ? null : hash('sha256', $existing);

		return new ArtifactDriftResult(
			$existingChecksum === $artifact->checksum,
			$existingChecksum,
			$artifact->checksum,
			$target
		);
	}
}
