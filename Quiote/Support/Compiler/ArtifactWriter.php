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
	public function write(EmittedArtifact $artifact, string $target): void;
}
