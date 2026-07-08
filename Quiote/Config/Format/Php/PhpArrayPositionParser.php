<?php
namespace Quiote\Config\Format\Php;

/**
 * Best-effort key-path -> {file, line} index for a PHP-array config file's
 * own `return [...]` literal. Unlike XML, no per-handler reconciliation is
 * needed: a hand-authored PHP config file's array literal already IS the
 * canonical array, verbatim (see FactoryConfigHandler/PluginConfigHandler's
 * own docblocks), so the tokenizer's key paths already match the canonical
 * array's key names directly.
 *
 * Uses \PhpToken::tokenize() rather than the legacy token_get_all(): every
 * token -- including single characters like `[`, `,`, `)` -- carries a real
 * ->line, where token_get_all() only gives line numbers on multi-character
 * tokens.
 *
 * Only literal nested arrays are descended into; any other value (a
 * function call, a constant, string concatenation, ...) is recorded as a
 * leaf position at its key/item's own line and then skipped as an opaque,
 * balanced-bracket expression -- this is a diagnostic position index, not a
 * second PHP evaluator, and never needs to understand what a value means.
 *
 * Never throws: a position-tracking failure must not block the real
 * PhpArrayFormatDriver::load() path, which parses the file completely
 * independently via a plain `require`.
 * @since      1.0.0
 */
final class PhpArrayPositionParser
{
	private const SKIP_TOKENS = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

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
			$tokens = \PhpToken::tokenize($source);
		} catch (\Throwable) {
			return [];
		}

		$index = self::findReturnValueStart($tokens);
		if ($index === null) {
			return [];
		}

		$positions = [];
		try {
			self::walkArrayLiteral($tokens, $index, $path, '', $positions);
		} catch (\Throwable) {
			return [];
		}

		return $positions;
	}

	/**
	 * @param array<int, \PhpToken> $tokens
	 */
	private static function findReturnValueStart(array $tokens): ?int
	{
		$count = count($tokens);
		$i = 0;
		while ($i < $count && $tokens[$i]->id !== \T_RETURN) {
			$i++;
		}
		if ($i >= $count) {
			return null;
		}

		$i = self::skipTrivia($tokens, $i + 1);
		if ($i >= $count) {
			return null;
		}

		if ($tokens[$i]->text === '[') {
			return $i;
		}

		if ($tokens[$i]->id === \T_ARRAY) {
			$after = self::skipTrivia($tokens, $i + 1);
			if (isset($tokens[$after]) && $tokens[$after]->text === '(') {
				return $after;
			}
		}

		return null;
	}

	/**
	 * @param array<int, \PhpToken> $tokens
	 */
	private static function skipTrivia(array $tokens, int $index): int
	{
		$count = count($tokens);
		while ($index < $count && in_array($tokens[$index]->id, self::SKIP_TOKENS, true)) {
			$index++;
		}
		return $index;
	}

	/**
	 * Walks one array literal starting at $tokens[$index] (the opening `[`
	 * or the `(` of an `array (`), returns the index just past its
	 * matching closer.
	 * @param array<int, \PhpToken> $tokens
	 * @param array<string, array{file: string, line: int}> $positions
	 */
	private static function walkArrayLiteral(array $tokens, int $index, string $file, string $pathPrefix, array &$positions): int
	{
		$count = count($tokens);
		$closer = $tokens[$index]->text === '[' ? ']' : ')';
		$index++;
		$listIndex = 0;

		while (true) {
			$index = self::skipTrivia($tokens, $index);
			if ($index >= $count) {
				return $index;
			}
			if ($tokens[$index]->text === $closer) {
				return $index + 1;
			}
			if ($tokens[$index]->text === ',') {
				// A stray/leading comma (e.g. right after the opener); nothing to record.
				$index++;
				continue;
			}

			$itemStart = $index;
			$key = self::tryParseKey($tokens, $index);

			if ($key !== null) {
				[$keyValue, $keyLine, $afterArrow] = $key;
				$childPath = self::joinPath($pathPrefix, $keyValue);
				$index = self::consumeValue($tokens, $afterArrow, $file, $childPath, $keyLine, $positions);
			} else {
				$childPath = self::joinPath($pathPrefix, $listIndex);
				$listIndex++;
				$index = self::consumeValue($tokens, $itemStart, $file, $childPath, $tokens[$itemStart]->line, $positions);
			}

			$index = self::skipTrivia($tokens, $index);
			if ($index < $count && $tokens[$index]->text === ',') {
				$index++;
			}
		}
	}

	/**
	 * @param array<int, \PhpToken> $tokens
	 * @return array{0: string|int, 1: int, 2: int}|null [keyValue, keyLine, indexAfterDoubleArrow]
	 */
	private static function tryParseKey(array $tokens, int $index): ?array
	{
		$count = count($tokens);
		$token = $tokens[$index];
		if (!in_array($token->id, [\T_CONSTANT_ENCAPSED_STRING, \T_LNUMBER], true)) {
			return null;
		}

		$after = self::skipTrivia($tokens, $index + 1);
		if ($after >= $count || $tokens[$after]->id !== \T_DOUBLE_ARROW) {
			return null;
		}

		$keyValue = self::evaluateLiteral($token);
		if (!is_string($keyValue) && !is_int($keyValue)) {
			return null;
		}

		return [$keyValue, $token->line, $after + 1];
	}

	private static function evaluateLiteral(\PhpToken $token): mixed
	{
		// $token->text is exactly one tokenizer-verified string/number
		// literal (nothing else can reach here, see tryParseKey()'s id
		// check) -- decoding it this way handles quoting/escaping/numeric
		// bases exactly like PHP itself, and is not a new attack surface:
		// the whole file is already executed via require() by
		// PhpArrayFormatDriver::parse() in the same code path.
		try {
			return eval('return ' . $token->text . ';');
		} catch (\Throwable) {
			return null;
		}
	}

	private static function joinPath(string $prefix, string|int $key): string
	{
		if (is_int($key)) {
			return "{$prefix}[{$key}]";
		}
		return $prefix === '' ? $key : "{$prefix}.{$key}";
	}

	/**
	 * Consumes one value expression starting at $index: a nested array/
	 * array() literal is recursed into (no leaf position recorded for the
	 * container key itself, only its own leaves); anything else is
	 * recorded as a leaf position at $line, then skipped as an opaque,
	 * balanced-bracket expression up to the next top-level "," or the
	 * caller's own closer.
	 * @param array<int, \PhpToken> $tokens
	 * @param array<string, array{file: string, line: int}> $positions
	 */
	private static function consumeValue(array $tokens, int $index, string $file, string $path, int $line, array &$positions): int
	{
		$count = count($tokens);
		$index = self::skipTrivia($tokens, $index);
		if ($index >= $count) {
			return $index;
		}

		if ($tokens[$index]->text === '[') {
			return self::walkArrayLiteral($tokens, $index, $file, $path, $positions);
		}
		if ($tokens[$index]->id === \T_ARRAY) {
			$after = self::skipTrivia($tokens, $index + 1);
			if (isset($tokens[$after]) && $tokens[$after]->text === '(') {
				return self::walkArrayLiteral($tokens, $after, $file, $path, $positions);
			}
		}

		$positions[$path] = ['file' => $file, 'line' => $line];

		$depth = 0;
		while ($index < $count) {
			$text = $tokens[$index]->text;
			if ($depth === 0 && ($text === ',' || $text === ']' || $text === ')')) {
				return $index;
			}
			if ($text === '[' || $text === '(' || $text === '{') {
				$depth++;
			} elseif ($text === ']' || $text === ')' || $text === '}') {
				$depth--;
			}
			$index++;
		}

		return $index;
	}
}

?>
