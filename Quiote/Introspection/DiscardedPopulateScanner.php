<?php
declare(strict_types=1);

namespace Quiote\Introspection;

use Quiote\Support\Compiler\Diagnostic;

/**
 * Finds `->setAttribute('populate', ...)` statements whose result nothing keeps, in every PHP file
 * under the module directories it is given.
 *
 * That statement is how an Agavi view prefilled a form. The request is immutable now, so the call
 * returns a new request and changes nothing: the form renders empty, and an edit page submitted
 * from it fails validation or overwrites the stored record with blanks. The statement compiles
 * and reads as deliberate, so it is reported, pointing at {@see \Quiote\View\View::populateForm()}.
 *
 * Reported whatever the receiver is, with or without the namespace argument Agavi took. A call
 * whose result is assigned, returned or passed on is somebody's deliberate code and is left out.
 *
 * @since      4.6.0
 */
final class DiscardedPopulateScanner
{
	/** Tokens a receiver chain such as `$this->requestState->current()` is made of. */
	private const array CHAIN_TOKENS = [
		T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
		T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_STATIC,
	];

	/**
	 * @param list<string> $moduleDirectories
	 * @return list<Diagnostic>
	 */
	public function scan(array $moduleDirectories): array
	{
		$diagnostics = [];
		foreach ($moduleDirectories as $directory) {
			if (!is_dir($directory)) {
				continue;
			}
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
			foreach ($files as $file) {
				if (!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
					continue;
				}
				foreach ($this->scanFile($file->getPathname()) as $diagnostic) {
					$diagnostics[] = $diagnostic;
				}
			}
		}
		return $diagnostics;
	}

	/**
	 * @return list<Diagnostic>
	 */
	public function scanFile(string $path): array
	{
		$source = @file_get_contents($path);
		if (!is_string($source)) {
			\Quiote\Logging\Log::for($this)->warning('could not read "' . $path . '"; it was not checked for a discarded populate.');
			return [];
		}
		if (stripos($source, 'populate') === false) {
			return [];
		}

		$tokens = $this->significantTokens($source);
		$diagnostics = [];
		foreach ($tokens as $index => $token) {
			if (is_array($token) && $this->isDiscardedPopulate($tokens, $index)) {
				$diagnostics[] = new Diagnostic(
					Diagnostic::SEVERITY_WARNING,
					Diagnostic::CODE_DISCARDED_POPULATE,
					"setAttribute('populate', ...) discards the request it returns, so this form is never filled. "
					. 'Call $this->populateForm($formId, $values) in the view instead.',
					$path,
					line: $token[2],
				);
			}
		}
		return $diagnostics;
	}

	/**
	 * The file's tokens without whitespace and comments, renumbered from zero.
	 *
	 * @return list<string|array{0: int, 1: string, 2: int}>
	 */
	private function significantTokens(string $source): array
	{
		$significant = [];
		foreach (token_get_all($source) as $token) {
			if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$significant[] = $token;
		}
		return $significant;
	}

	/**
	 * Whether the token at $index is the method name of a `setAttribute('populate', ...)` call that
	 * makes up a whole statement.
	 *
	 * @param list<string|array{0: int, 1: string, 2: int}> $tokens
	 */
	private function isDiscardedPopulate(array $tokens, int $index): bool
	{
		$name = $tokens[$index];
		if (!is_array($name) || $name[0] !== T_STRING || strcasecmp($name[1], 'setAttribute') !== 0) {
			return false;
		}
		$operator = $tokens[$index - 1] ?? null;
		if (!is_array($operator) || !in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
			return false;
		}
		if (($tokens[$index + 1] ?? null) !== '(') {
			return false;
		}
		$firstArgument = $tokens[$index + 2] ?? null;
		if (!is_array($firstArgument) || $firstArgument[0] !== T_CONSTANT_ENCAPSED_STRING || substr($firstArgument[1], 1, -1) !== 'populate') {
			return false;
		}

		return $this->endsTheStatement($tokens, $index + 1) && $this->startsTheStatement($tokens, $index - 1);
	}

	/**
	 * Whether the call whose argument list opens at $open is followed by the end of the statement.
	 *
	 * @param list<string|array{0: int, 1: string, 2: int}> $tokens
	 */
	private function endsTheStatement(array $tokens, int $open): bool
	{
		$depth = 0;
		for ($i = $open, $count = count($tokens); $i < $count; $i++) {
			if ($tokens[$i] === '(' || $tokens[$i] === '[') {
				$depth++;
			} elseif ($tokens[$i] === ')' || $tokens[$i] === ']') {
				$depth--;
				if ($depth === 0) {
					$next = $tokens[$i + 1] ?? null;
					return $next === ';' || (is_array($next) && $next[0] === T_CLOSE_TAG);
				}
			}
		}
		return false;
	}

	/**
	 * Whether nothing but the receiver chain stands between the statement's start and $operator:
	 * no assignment, `return`, argument list or operator that would keep the result.
	 *
	 * @param list<string|array{0: int, 1: string, 2: int}> $tokens
	 */
	private function startsTheStatement(array $tokens, int $operator): bool
	{
		$depth = 0;
		for ($i = $operator; $i >= 0; $i--) {
			$token = $tokens[$i];
			if ($token === ')' || $token === ']') {
				$depth++;
				continue;
			}
			if ($token === '(' || $token === '[') {
				if ($depth === 0) {
					// An unclosed bracket: the call sits inside an argument list or an array.
					return false;
				}
				$depth--;
				continue;
			}
			if ($depth > 0) {
				continue;
			}
			if ($token === ';' || $token === '{' || $token === '}' || $token === ':') {
				return true;
			}
			if (is_array($token)) {
				if (in_array($token[0], [T_OPEN_TAG, T_ELSE], true)) {
					return true;
				}
				if (in_array($token[0], self::CHAIN_TOKENS, true)) {
					continue;
				}
			}
			return false;
		}
		return true;
	}
}
