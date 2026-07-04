<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ParseException;
use Quiote\Util\Toolkit;

/**
 * CompileConfigHandler gathers multiple files and puts them into a single
 * file. Upon creation of the new file, all comments and blank lines are removed.
 *
 * Migrated to IArrayConfigHandler (phase 2). Canonical schema:
 * ['resolved_file_path' => 'code_to_embed'],
 * exactly the map execute() used to build inline and hand straight to
 * generate() (which concatenates the values). Gathering/reading/
 * formatting the referenced files still happens in toCanonicalArray() --
 * unlike other handlers' extraction step, this one is inherently about
 * resolving and reading files the config points at, not just walking the
 * DOM, so there's little left for executeArray() to do but hand the
 * already-built map to generate().
 * @since      1.0.0
 * @version    1.0.0
 */
class CompileConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/compile/1.1';

	/**
	 * @throws     <b>ParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'compile');

		$config = $document->documentURI;

		$data = [];

		// let's do our fancy work
		foreach ($document->getConfigurationElements() as $configuration) {
			if (!$configuration->has('compiles')) {
				continue;
			}

			foreach ($configuration->get('compiles') as $compileFile) {
				$file = trim((string) $compileFile->getValue());

				$file = Toolkit::expandDirectives($file);
				$file = self::replacePath($file);
				$file = realpath($file);

				if (!is_readable($file)) {
					// file doesn't exist
					$error = 'Configuration file "%s" specifies nonexistent ' . 'or unreadable file "%s"';
					$error = sprintf($error, $config, $compileFile->getValue());
					throw new ParseException($error);
				}

				if (Config::get('core.debug', false)) {
					// debug mode, just require() the files, makes for nicer stack traces
					$contents = 'require(' . var_export($file, true) . ');';
				} else {
					// no debug mode, so make things fast
					$contents = $this->formatFile(file_get_contents($file));
				}

				// append file data
				$data[$file] = $contents;
			}
		}

		return $data;
	}

	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		return $this->generate($config, $sourceRef);
	}

	/**
	 * Given some data, remove unnecessary formatting and return the new data
	 * @param      string Data to format for a compiled file, probably PHP code
	 * @return     string Data with unnecessary content removed
	 * @since      1.0.0
	 */
	protected function formatFile($data)
	{
		// replace windows and mac format with unix format
		$data = str_replace("\r\n", "\n", $data);
		$data = str_replace("\r", "\n", $data);

		// remove comments and tags with tokenizer

		// I disabled this, it seems broken somehow. doesn't remove all <?php tags. - david

		if (function_exists('token_get_all')) {
			$tokens = token_get_all($data);
			$tokenized = null;
			// has something been written to tokenized? If so, we can optionally append whitespace.
			$appended = false;

			foreach ($tokens as $token) {

				if (is_string($token)) {
					$tokenized .= $token;
					$appended = true;
				} else {
					@[$id, $text] = $token;
					switch ($id) {
						case T_COMMENT:
						case T_DOC_COMMENT:
						case T_OPEN_TAG:
							$appended = false;
							break;
						case T_CLOSE_TAG:
							$appended = false;
							break;

						case T_WHITESPACE:
							// something was appended, optionally add a newline
							if ($appended) {
								$replace = null;
								if (str_contains($text, "\n")) {
									$replace = "\n";
								}
								if ($replace) {
									$text = preg_replace('/\s+/m', $replace, $text);
								}
								$tokenized .= $text;
							}
							$appended = false;
							break;

						case T_INLINE_HTML:
							// If empty T_INLINE_HTML move on
							if (!preg_match('/[^\s]+/m', $text)) {
								$appended = false;
								break;
							}

						default:
							$tokenized .= $text;
							$appended = true;
							break;
					}
				}
			}
			$data = $tokenized;
		}
		$data = trim($data);
		if (str_starts_with($data, '<?php')) {
			$data = substr($data, 5);
		} elseif (str_starts_with($data, '<?')) {
			$data = substr($data, 2);
		}
		if (str_ends_with($data, '?>')) {
			$data = substr($data, 0, -2);
		}
		$data = preg_replace('/\s*\?>\s*<\?(php)?\s*/', '', $data);

		return $data;
	}

}

?>
