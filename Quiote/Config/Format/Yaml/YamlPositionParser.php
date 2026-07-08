<?php
namespace Quiote\Config\Format\Yaml;

/**
 * Best-effort key-path -> {file, line} index for a YAML config file, built
 * by a block-style line scanner rather than a full YAML implementation.
 * The real data always comes from YamlFormatDriver's unchanged
 * Symfony\Component\Yaml\Yaml::parseFile() call -- this is a separate,
 * parallel pass purely for diagnostic positions, and a failure here must
 * never affect that real parse.
 *
 * Scoped to what this framework's own config files actually use: block
 * mappings, block sequences (both "key: then indented dashes" and the one
 * YAML exception where a sequence sits at the SAME indent as its key), and
 * dash-items that are themselves inline mappings (`- class: ...` then
 * `  enabled: ...`). Flow collections (`{...}`/`[...]`) and multi-line
 * block scalars (`|`/`>`) are recorded as opaque leaves at their key's own
 * line, never descended into -- a documented limitation, not a silent gap,
 * matching how the PHP-array slice treats an arbitrary expression value.
 * @since      1.0.0
 */
final class YamlPositionParser
{
	private const SINGLE_QUOTED_KEY_PATTERN = '/^\'((?:[^\']|\'\')*)\'\s*:(?:\s+(.*)|)\s*$/u';
	private const DOUBLE_QUOTED_KEY_PATTERN = '/^"((?:[^"\\\\]|\\\\.)*)"\s*:(?:\s+(.*)|)\s*$/u';
	private const BARE_KEY_PATTERN = '/^([^:\s\[\]{}][^:]*?)\s*:(?:\s+(.*)|)\s*$/u';

	/**
	 * @return array<string, array{file: string, line: int}>
	 */
	public static function parse(string $path): array
	{
		$source = @file_get_contents($path);
		if ($source === false) {
			return [];
		}

		try {
			$lines = self::contentLines($source);
			if ($lines === []) {
				return [];
			}

			$positions = [];
			self::parseNode($lines, 0, count($lines), '', $path, $positions);
			return $positions;
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * @return list<array{line: int, indent: int, text: string}>
	 */
	private static function contentLines(string $source): array
	{
		$rawLines = preg_split('/\r\n|\r|\n/', $source);
		if ($rawLines === false) {
			return [];
		}

		$lines = [];
		foreach ($rawLines as $lineNumberZeroBased => $raw) {
			$trimmed = ltrim($raw, " \t");
			if ($trimmed === '' || $trimmed[0] === '#') {
				continue;
			}
			if ($trimmed === '---' || str_starts_with($trimmed, '%YAML') || str_starts_with($trimmed, '%TAG')) {
				continue;
			}
			$lines[] = [
				'line' => $lineNumberZeroBased + 1,
				'indent' => strlen($raw) - strlen($trimmed),
				'text' => rtrim($trimmed),
			];
		}
		return $lines;
	}

	/**
	 * @param list<array{line: int, indent: int, text: string}> $lines
	 * @param array<string, array{file: string, line: int}> $positions
	 * @return int Index of the first line not consumed by this node.
	 */
	private static function parseNode(array $lines, int $index, int $count, string $pathPrefix, string $file, array &$positions): int
	{
		if ($index >= $count) {
			return $index;
		}

		if (str_starts_with($lines[$index]['text'], '-')) {
			return self::parseList($lines, $index, $count, $lines[$index]['indent'], $pathPrefix, $file, $positions);
		}

		return self::parseMap($lines, $index, $count, $lines[$index]['indent'], $pathPrefix, $file, $positions);
	}

	/**
	 * @param list<array{line: int, indent: int, text: string}> $lines
	 * @param array<string, array{file: string, line: int}> $positions
	 */
	private static function parseMap(array $lines, int $index, int $count, int $indent, string $pathPrefix, string $file, array &$positions): int
	{
		while ($index < $count && $lines[$index]['indent'] === $indent) {
			$line = $lines[$index];
			if (str_starts_with($line['text'], '-')) {
				// A sibling sequence at this exact indent is not a map entry;
				// let the caller (which is expecting a map) stop here.
				break;
			}

			$parsed = self::tryParseKey($line['text']);
			if ($parsed === null) {
				// Doesn't look like "key: ..." at all -- stop, rather than
				// misinterpret arbitrary content as a key.
				break;
			}

			[$key, $rest] = $parsed;
			$childPath = self::joinMapPath($pathPrefix, $key);
			$index++;

			if ($rest !== '') {
				$positions[$childPath] = ['file' => $file, 'line' => $line['line']];
				continue;
			}

			if ($index < $count && self::isChildOf($lines[$index], $indent)) {
				$index = self::parseNode($lines, $index, $count, $childPath, $file, $positions);
			}
			// Else: the key had no inline value and no deeper/sibling-list
			// child -- an implicit null, nothing to record.
		}

		return $index;
	}

	/**
	 * @param list<array{line: int, indent: int, text: string}> $lines
	 * @param array<string, array{file: string, line: int}> $positions
	 */
	private static function parseList(array $lines, int $index, int $count, int $indent, string $pathPrefix, string $file, array &$positions): int
	{
		$itemIndex = 0;

		while ($index < $count && $lines[$index]['indent'] === $indent && str_starts_with($lines[$index]['text'], '-')) {
			$line = $lines[$index];
			$itemPath = "{$pathPrefix}[{$itemIndex}]";
			$itemIndex++;

			$rest = ltrim(substr($line['text'], 1));
			if ($rest === '') {
				$index++;
				if ($index < $count && $lines[$index]['indent'] > $indent) {
					$index = self::parseNode($lines, $index, $count, $itemPath, $file, $positions);
				}
				continue;
			}

			$contentColumn = $line['indent'] + (strlen($line['text']) - strlen($rest));

			$parsed = self::tryParseKey($rest);
			if ($parsed !== null) {
				[$key, $inlineRest] = $parsed;
				$childPath = self::joinMapPath($itemPath, $key);

				if ($inlineRest !== '') {
					$positions[$childPath] = ['file' => $file, 'line' => $line['line']];
				}
				$index++;

				if ($inlineRest === '') {
					if ($index < $count && self::isChildOf($lines[$index], $contentColumn)) {
						$index = self::parseNode($lines, $index, $count, $childPath, $file, $positions);
					}
				}

				// Further keys of this same inline map continue at $contentColumn.
				if ($index < $count && $lines[$index]['indent'] === $contentColumn && !str_starts_with($lines[$index]['text'], '-')) {
					$index = self::parseMap($lines, $index, $count, $contentColumn, $itemPath, $file, $positions);
				}
			} else {
				$positions[$itemPath] = ['file' => $file, 'line' => $line['line']];
				$index++;
			}
		}

		return $index;
	}

	/**
	 * Whether $line is a child of a node whose own key/item starts at
	 * $parentIndent -- either genuinely deeper-indented, or (the one block
	 * YAML exception) a sequence at the exact same indent as its mapping key.
	 * @param array{line: int, indent: int, text: string} $line
	 */
	private static function isChildOf(array $line, int $parentIndent): bool
	{
		if ($line['indent'] > $parentIndent) {
			return true;
		}
		return $line['indent'] === $parentIndent && str_starts_with($line['text'], '-');
	}

	/**
	 * Matches "key: rest" (bare, single-quoted, or double-quoted key) at
	 * the START of $text. Which quote style to try is decided by $text's
	 * own first character -- unambiguous, unlike relying on which regex
	 * capture group came back non-empty (an empty single-quoted key `'':`
	 * is valid YAML and would otherwise be indistinguishable from "no
	 * match").
	 * @return array{0: string, 1: string}|null [key, restAfterColon]
	 */
	private static function tryParseKey(string $text): ?array
	{
		if ($text === '') {
			return null;
		}

		if ($text[0] === "'") {
			if (!preg_match(self::SINGLE_QUOTED_KEY_PATTERN, $text, $m)) {
				return null;
			}
			return [str_replace("''", "'", $m[1]), $m[2] ?? ''];
		}

		if ($text[0] === '"') {
			if (!preg_match(self::DOUBLE_QUOTED_KEY_PATTERN, $text, $m)) {
				return null;
			}
			return [stripcslashes($m[1]), $m[2] ?? ''];
		}

		if (!preg_match(self::BARE_KEY_PATTERN, $text, $m)) {
			return null;
		}
		return [$m[1], $m[2] ?? ''];
	}

	private static function joinMapPath(string $prefix, string $key): string
	{
		return $prefix === '' ? $key : "{$prefix}.{$key}";
	}
}

?>
