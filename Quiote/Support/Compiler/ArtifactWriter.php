<?php
namespace Quiote\Support\Compiler;

/**
 * Writes an EmittedArtifact to disk. Emitters never write files themselves
 * (see EmitterInterface) so that a future CLI's --check mode can compare
 * against disk without ever touching it, and so tests can emit without
 * filesystem side effects.
 * @since      1.0.0
 */
interface ArtifactWriter
{
	/**
	 * Persists the artifact's PHP source at $target.
	 *
	 * Implementations must create whatever parent structure $target needs, and
	 * must never leave a partially written artifact visible at that path: either
	 * the complete artifact is there afterwards, or an exception is thrown.
	 */
	public function write(EmittedArtifact $artifact, string $target): void;
}
