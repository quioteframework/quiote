<?php
namespace Quiote\Support\Compiler;

use RuntimeException;

/**
 * Writes an EmittedArtifact to a real file, via a write-to-temp-then-rename
 * so a concurrent request (or an opcache-warmed worker) never observes a
 * partially written compiled validator file -- rename() is atomic on the
 * same filesystem, unlike a direct file_put_contents() to the final path.
 * @since      1.0.0
 */
final class FilesystemArtifactWriter implements ArtifactWriter
{
	/**
	 * {@inheritDoc}
	 *
	 * Creates the target directory when missing, writes the source to a
	 * process-unique temporary file beside it and renames that into place. A
	 * failed rename removes the temporary file before throwing.
	 *
	 * @throws     RuntimeException If the directory cannot be created, the temporary
	 *                              file cannot be written, or the rename fails.
	 */
	public function write(EmittedArtifact $artifact, string $target): void
	{
		$dir = dirname($target);
		if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new RuntimeException('Unable to create directory: ' . $dir);
		}

		$tmp = $target . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
		if (file_put_contents($tmp, $artifact->phpSource) === false) {
			throw new RuntimeException('Unable to write temporary artifact file: ' . $tmp);
		}

		if (!rename($tmp, $target)) {
			@unlink($tmp);
			throw new RuntimeException('Unable to move artifact into place: ' . $target);
		}
	}
}
