<?php
namespace Quiote\Config\Format\Xml;

/**
 * Maps a merged (post-importNode()) DOMElement to the {file, line} it was
 * cloned from, built incrementally by XmlConfigParser::run() while it
 * merges per-file documents into the final result. Keyed by spl_object_id()
 * of the MERGED element -- the one a handler's
 * toCanonicalArrayWithPositions() actually holds when it looks a position
 * up, not the pre-merge original.
 *
 * PHP's DOM extension only returns the SAME wrapper object for a given
 * underlying libxml node while at least one PHP reference to that wrapper
 * is still alive; once nothing references it, the wrapper is garbage
 * collected and the next traversal that reaches the same node creates a
 * brand new wrapper (with an unrelated, possibly-recycled spl_object_id()).
 * Recording only the id -- not the object -- would let every recorded
 * element be collected the moment correlatePosition()'s local variables go
 * out of scope, so every later forElement() lookup would silently miss.
 * Keeping a reference here for the index's own lifetime is what makes
 * spl_object_id()-keying actually work as a stable handle.
 * @since      1.0.0
 */
final class ElementPositionIndex
{
	/** @var array<int, array{element: \DOMElement, file: string, line: int}> */
	private array $positions = [];

	public function record(\DOMElement $element, string $file, int $line): void
	{
		$this->positions[spl_object_id($element)] = ['element' => $element, 'file' => $file, 'line' => $line];
	}

	/**
	 * @return array{file: string, line: int}|null
	 */
	public function forElement(\DOMElement $element): ?array
	{
		$entry = $this->positions[spl_object_id($element)] ?? null;
		if ($entry === null) {
			return null;
		}
		return ['file' => $entry['file'], 'line' => $entry['line']];
	}
}

?>
