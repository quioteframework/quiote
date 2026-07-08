<?php
declare(strict_types=1);

namespace Quiote\Introspection;

use RuntimeException;

/**
 * Writes the `cache/introspection/app.json` artifact via a
 * write-to-temp-then-rename, so an editor extension polling the file never
 * observes a partial write mid-regeneration -- the same technique
 * `Quiote\Support\Compiler\FilesystemArtifactWriter` uses for compiled PHP
 * artifacts, just for arbitrary JSON content instead of PHP source.
 * @since      1.0.0
 */
final class AppIntrospectionArtifactWriter
{
	/**
	 * @param array<string, mixed> $artifact
	 */
	public function write(array $artifact, string $target): void
	{
		$json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new RuntimeException('Unable to encode the introspection artifact as JSON.');
		}

		$dir = dirname($target);
		if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
			throw new RuntimeException('Unable to create directory: ' . $dir);
		}

		$tmp = $target . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
		if (file_put_contents($tmp, $json) === false) {
			throw new RuntimeException('Unable to write temporary artifact file: ' . $tmp);
		}

		if (!rename($tmp, $target)) {
			@unlink($tmp);
			throw new RuntimeException('Unable to move artifact into place: ' . $target);
		}
	}
}
