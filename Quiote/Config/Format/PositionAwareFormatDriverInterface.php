<?php
namespace Quiote\Config\Format;

/**
 * Opt-in "locating" parse mode (see VSCODE_EXTENSION_INTEGRATION.md's config
 * validator work item 3): same canonical array a plain load() would
 * produce, plus a key-path -> {file, line} index for whichever keys the
 * driver could trace back to a source position. Formalizes the shape
 * XmlFormatDriver::loadWithPositions() already has, so a caller (e.g. a
 * future validate_config probe capability) can use it generically
 * regardless of which format actually produced the config.
 * @since      1.0.0
 */
interface PositionAwareFormatDriverInterface
{
	/**
	 * @return array{data: array<string, mixed>, positions: array<string, array{file: string, line: int}>}
	 */
	public function loadWithPositions(string $path, ?string $environment, ?string $context = null): array;
}

?>
